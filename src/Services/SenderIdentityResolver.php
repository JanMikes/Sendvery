<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\SenderIdentity;
use App\Repository\SenderIdentityRepository;
use App\Services\Dns\ForwardConfirmedReverseDns;
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
 * what DEC-059 D11 was about: at most {@see MAX_IDENTIFICATIONS_PER_BATCH}
 * addresses are identified live per call, and anything beyond that is returned
 * unresolved and picked up by a later report. No AI, no blocking enrichment,
 * nothing that can stall a worker.
 *
 * Identification includes forward-confirming the PTR hostname
 * ({@see ForwardConfirmedReverseDns}) before any trust is placed in it, because
 * a reverse record is written by whoever holds the IP block and a forged one
 * used to be enough to be classified as a harmless forwarder and silence the
 * new-sender alert.
 */
final readonly class SenderIdentityResolver
{
    /**
     * Hard cap on addresses identified live per batch. Reports normally carry a
     * handful of source IPs, and the cache means a busy domain converges after
     * one or two ingests — so this only ever bites on a pathological report,
     * which is exactly when a worker must not be allowed to stall.
     *
     * Identifying one address costs at most two DNS queries: the reverse
     * lookup, then the forward lookup that confirms it. The cap is deliberately
     * expressed in identifications rather than lookups so that forward
     * confirmation tightened this budget instead of doubling it — a batch still
     * issues at most 24 queries, where before it issued 25.
     */
    public const int MAX_IDENTIFICATIONS_PER_BATCH = 12;

    public function __construct(
        private SenderIdentityRepository $repository,
        private ReverseDnsResolver $reverseDns,
        private ForwardConfirmedReverseDns $forwardConfirmation,
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
        $identificationsRemaining = self::MAX_IDENTIFICATIONS_PER_BATCH;
        $resolved = [];

        foreach ($distinctIps as $sourceIp) {
            $identity = $cached[$sourceIp] ?? null;
            $signals = $signalsByIp[$sourceIp] ?? null;

            if (null !== $identity && !$identity->isDueForRetry($now)) {
                $resolved[$sourceIp] = $this->present($identity, $signals);

                continue;
            }

            if ($identificationsRemaining <= 0) {
                // Budget spent. Report what we already know (possibly nothing)
                // without recording an attempt, so the IP keeps its place in the
                // retry schedule and gets a real lookup on the next ingest.
                $resolved[$sourceIp] = null === $identity
                    ? ResolvedSender::unresolved($sourceIp)
                    : $this->present($identity, $signals);

                continue;
            }

            --$identificationsRemaining;
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
        // Asked once, here, and cached with the rest of the facts: the answer is
        // as global and as static as the hostname it validates. With no hostname
        // there is no claim to confirm, so the question stays unasked (null)
        // rather than being recorded as a failure.
        $forwardConfirmed = null === $hostname
            ? null
            : $this->forwardConfirmation->confirms($sourceIp, $hostname);

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
            role: $this->classifier->baselineRole($hostname, $organization, true === $forwardConfirmed),
            forwardConfirmed: $forwardConfirmed,
            at: $now,
        );

        return $identity;
    }

    /**
     * Applies the caller's own signals on top of the cached facts. The result is
     * never written back: OwnRelay and Suspicious are per-team verdicts and the
     * cache is shared by everyone.
     *
     * The role is always re-derived rather than read off the row, so that the
     * forward-confirmation flag gates every answer this service gives — including
     * for rows written before confirmation existed, and for rows a spent lookup
     * budget could not refresh yet.
     */
    private function present(SenderIdentity $identity, ?SenderAuthSignals $signals): ResolvedSender
    {
        return ResolvedSender::fromIdentity(
            $identity,
            $this->classifier->classify(
                $identity->hostname,
                $identity->organization,
                $signals,
                $identity->isForwardConfirmed(),
            ),
        );
    }
}
