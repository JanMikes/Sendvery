<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\SenderIdentity;
use App\Repository\SenderIdentityRepository;
use App\Services\Dns\ReverseDnsResolver;
use App\Value\ResolvedSender;
use App\Value\SenderAuthSignals;
use Psr\Clock\ClockInterface;

/**
 * Turns source IPs into identified senders (DEC-059 §3.4). This is the public
 * surface every other package in DEC-059 consumes.
 *
 * Cache first, always: `sender_identity` is a global table, so an IP is looked
 * up once for the whole system instead of once per report per domain. Misses
 * perform one bounded reverse lookup and persist the outcome — *including*
 * negative outcomes, which is the part that stops the same PTR-less IPs being
 * re-queried on every single ingest.
 *
 * Ingest can therefore never chain an unbounded number of DNS lookups, which is
 * what DEC-059 D11 was about: at most {@see MAX_LOOKUPS_PER_BATCH} live lookups
 * happen per call, and anything beyond that is returned unresolved and picked
 * up by a later report. No AI, no blocking enrichment, nothing that can stall a
 * worker.
 */
final readonly class SenderIdentityResolver
{
    /**
     * Hard cap on live reverse lookups per batch. Reports normally carry a
     * handful of source IPs, and the cache means a busy domain converges after
     * one or two ingests — so this only ever bites on a pathological report,
     * which is exactly when a worker must not be allowed to stall.
     */
    private const int MAX_LOOKUPS_PER_BATCH = 25;

    public function __construct(
        private SenderIdentityRepository $repository,
        private ReverseDnsResolver $reverseDns,
        private RegistrableDomainExtractor $registrableDomainExtractor,
        private OrganizationMapper $organizationMapper,
        private SenderRoleClassifier $classifier,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Resolves a single IP. Prefer {@see resolveMany()} when more than one IP is
     * in play — it issues one query instead of N.
     */
    public function resolve(string $sourceIp, ?SenderAuthSignals $signals = null): ResolvedSender
    {
        $resolved = $this->resolveMany(
            [$sourceIp],
            null === $signals ? [] : [$sourceIp => $signals],
        );

        return $resolved[$sourceIp];
    }

    /**
     * Batch entry point — the one report ingest uses.
     *
     * @param list<string>                     $sourceIps
     * @param array<string, SenderAuthSignals> $signalsByIp per-IP auth evidence, keyed by IP; entries are optional
     *
     * @return array<string, ResolvedSender> keyed by source IP, one entry per distinct input IP
     */
    public function resolveMany(array $sourceIps, array $signalsByIp = []): array
    {
        $distinctIps = array_values(array_unique($sourceIps));

        if ([] === $distinctIps) {
            return [];
        }

        $cached = $this->repository->findByIps($distinctIps);
        $now = $this->clock->now();
        $lookupsRemaining = self::MAX_LOOKUPS_PER_BATCH;
        $resolved = [];

        foreach ($distinctIps as $sourceIp) {
            $identity = $cached[$sourceIp] ?? null;
            $signals = $signalsByIp[$sourceIp] ?? null;

            if (null !== $identity && !$identity->isDueForRetry($now)) {
                $resolved[$sourceIp] = $this->present($identity, $signals);

                continue;
            }

            if ($lookupsRemaining <= 0) {
                // Budget spent. Report what we already know (possibly nothing)
                // without recording an attempt, so the IP keeps its place in the
                // retry schedule and gets a real lookup on the next ingest.
                $resolved[$sourceIp] = null === $identity
                    ? ResolvedSender::unresolved($sourceIp)
                    : $this->present($identity, $signals);

                continue;
            }

            --$lookupsRemaining;
            $resolved[$sourceIp] = $this->present($this->lookUp($sourceIp, $identity, $now), $signals);
        }

        return $resolved;
    }

    /**
     * Performs the reverse lookup and writes the outcome into the cache,
     * creating the row on first sight.
     */
    private function lookUp(string $sourceIp, ?SenderIdentity $identity, \DateTimeImmutable $now): SenderIdentity
    {
        $hostname = $this->reverseDns->resolve($sourceIp);
        $registrableDomain = null === $hostname ? null : $this->registrableDomainExtractor->extract($hostname);
        $organization = null === $hostname ? null : $this->organizationMapper->resolve($hostname);

        if (null === $identity) {
            $identity = new SenderIdentity(
                id: $this->identityProvider->nextIdentity(),
                sourceIp: $sourceIp,
                resolvedAt: $now,
            );

            $this->repository->add($identity);
        }

        $identity->recordResolution(
            hostname: $hostname,
            registrableDomain: $registrableDomain,
            organization: $organization,
            role: $this->classifier->baselineRole($hostname, $organization),
            at: $now,
        );

        return $identity;
    }

    /**
     * Applies the caller's own signals on top of the cached facts. The result is
     * never written back: OwnRelay and Suspicious are per-team verdicts and the
     * cache is shared by everyone.
     */
    private function present(SenderIdentity $identity, ?SenderAuthSignals $signals): ResolvedSender
    {
        if (null === $signals) {
            return ResolvedSender::fromIdentity($identity);
        }

        return ResolvedSender::fromIdentity(
            $identity,
            $this->classifier->classify($identity->hostname, $identity->organization, $signals),
        );
    }
}
