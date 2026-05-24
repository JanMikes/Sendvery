<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\DnsHealthOverviewResult;
use App\Services\DomainSetupStatusResolver;
use App\Value\DomainHealthFilter;
use App\Value\ProtocolState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainSetupStatusResolverTest extends TestCase
{
    #[Test]
    public function resolveNullDnsHealthMarksAllUnknownAndPointsAtSpf(): void
    {
        $resolver = new DomainSetupStatusResolver();

        $status = $resolver->resolve(null);

        self::assertSame(DomainHealthFilter::Unverified, $status->severity);
        self::assertSame('DNS not configured yet — start with the SPF record', $status->headline);
        self::assertSame('dashboard_domain_health', $status->ctaRoute);
        self::assertSame('health-spf', $status->ctaFragment);
        self::assertCount(4, $status->protocols);
        foreach ($status->protocols as $protocol) {
            self::assertSame(ProtocolState::Unknown, $protocol->state);
        }
    }

    #[Test]
    public function resolveAllFieldsNullDtoActsLikeNullInput(): void
    {
        $resolver = new DomainSetupStatusResolver();

        $status = $resolver->resolve($this->buildDnsHealth(
            latestSpfScore: null,
            latestDkimScore: null,
            latestDmarcScore: null,
            latestMxScore: null,
        ));

        self::assertSame(DomainHealthFilter::Unverified, $status->severity);
        self::assertSame('DNS not configured yet — start with the SPF record', $status->headline);
        self::assertSame('dashboard_domain_health', $status->ctaRoute);
        self::assertSame('health-spf', $status->ctaFragment);
        self::assertCount(4, $status->protocols);
        foreach ($status->protocols as $protocol) {
            self::assertSame(ProtocolState::Unknown, $protocol->state);
        }
    }

    #[Test]
    public function resolveAllFourConfiguredYieldsHealthyWithNoCta(): void
    {
        $resolver = new DomainSetupStatusResolver();

        $status = $resolver->resolve($this->buildDnsHealth(
            spfVerifiedAt: new \DateTimeImmutable(),
            dkimVerifiedAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: new \DateTimeImmutable(),
            latestMxScore: 95,
        ));

        self::assertSame(DomainHealthFilter::Healthy, $status->severity);
        self::assertSame('Monitoring active — all four records are in place', $status->headline);
        self::assertNull($status->ctaLabel);
        self::assertNull($status->ctaRoute);
        self::assertNull($status->ctaFragment);
        foreach ($status->protocols as $protocol) {
            self::assertSame(ProtocolState::Configured, $protocol->state);
            self::assertNull($protocol->nextStep);
        }
    }

    #[Test]
    public function resolveDmarcMissingWithOthersOkYieldsUnverifiedAndPointsAtDmarc(): void
    {
        $resolver = new DomainSetupStatusResolver();

        $status = $resolver->resolve($this->buildDnsHealth(
            spfVerifiedAt: new \DateTimeImmutable(),
            dkimVerifiedAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: null,
            latestDmarcScore: null,
            latestMxScore: 95,
        ));

        self::assertSame(DomainHealthFilter::Unverified, $status->severity);
        self::assertSame('Setup incomplete — DMARC record not yet published', $status->headline);
        self::assertSame('health-dmarc', $status->ctaFragment);
        self::assertSame('dashboard_domain_health', $status->ctaRoute);
        $byName = $this->indexByName($status->protocols);
        self::assertSame(ProtocolState::Configured, $byName['SPF']->state);
        self::assertSame(ProtocolState::Configured, $byName['DKIM']->state);
        self::assertSame(ProtocolState::Missing, $byName['DMARC']->state);
        self::assertSame(ProtocolState::Configured, $byName['MX']->state);
    }

    #[Test]
    public function resolveDmarcOkSpfMissingYieldsAttentionWithSpfNextStep(): void
    {
        $resolver = new DomainSetupStatusResolver();

        $status = $resolver->resolve($this->buildDnsHealth(
            spfVerifiedAt: null,
            dkimVerifiedAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: new \DateTimeImmutable(),
            latestSpfScore: null,
            latestMxScore: 95,
        ));

        self::assertSame(DomainHealthFilter::Attention, $status->severity);
        self::assertStringContainsString('SPF', $status->headline);
        $byName = $this->indexByName($status->protocols);
        self::assertSame(ProtocolState::Missing, $byName['SPF']->state);
        self::assertNotNull($byName['SPF']->nextStep);
        self::assertStringContainsString('v=spf1', $byName['SPF']->nextStep);
        self::assertSame('health-spf', $status->ctaFragment);
    }

    #[Test]
    public function resolveDmarcOkDkimMissingMxFailingYieldsAttentionWithBothInHeadline(): void
    {
        $resolver = new DomainSetupStatusResolver();

        $status = $resolver->resolve($this->buildDnsHealth(
            spfVerifiedAt: new \DateTimeImmutable(),
            dkimVerifiedAt: null,
            dmarcVerifiedAt: new \DateTimeImmutable(),
            latestDkimScore: null,
            latestMxScore: 40,
        ));

        self::assertSame(DomainHealthFilter::Attention, $status->severity);
        self::assertStringContainsString('DKIM', $status->headline);
        self::assertStringContainsString('MX', $status->headline);
        $byName = $this->indexByName($status->protocols);
        self::assertSame(ProtocolState::Missing, $byName['DKIM']->state);
        self::assertSame(ProtocolState::Invalid, $byName['MX']->state);
        self::assertNotNull($byName['DKIM']->nextStep);
        self::assertNotNull($byName['MX']->nextStep);
        // DMARC OK, so most-urgent failing is DKIM (SPF > DKIM > MX precedence).
        self::assertSame('health-dkim', $status->ctaFragment);
    }

    #[Test]
    public function resolveDmarcOkSpfInvalidDkimMissingPicksSpfAsMostUrgent(): void
    {
        $resolver = new DomainSetupStatusResolver();

        $status = $resolver->resolve($this->buildDnsHealth(
            spfVerifiedAt: null,
            dkimVerifiedAt: null,
            dmarcVerifiedAt: new \DateTimeImmutable(),
            latestSpfScore: 30,
            latestDkimScore: null,
            latestMxScore: 95,
        ));

        self::assertSame(DomainHealthFilter::Attention, $status->severity);
        self::assertStringContainsString('SPF', $status->headline);
        self::assertStringContainsString('DKIM', $status->headline);
        $byName = $this->indexByName($status->protocols);
        self::assertSame(ProtocolState::Invalid, $byName['SPF']->state);
        self::assertSame(ProtocolState::Missing, $byName['DKIM']->state);
        self::assertSame('health-spf', $status->ctaFragment);
    }

    /**
     * @param list<\App\Results\ProtocolSetupStatus> $protocols
     *
     * @return array<string, \App\Results\ProtocolSetupStatus>
     */
    private function indexByName(array $protocols): array
    {
        $result = [];
        foreach ($protocols as $protocol) {
            $result[$protocol->name] = $protocol;
        }

        return $result;
    }

    private function buildDnsHealth(
        ?\DateTimeImmutable $spfVerifiedAt = null,
        ?\DateTimeImmutable $dkimVerifiedAt = null,
        ?\DateTimeImmutable $dmarcVerifiedAt = null,
        ?int $latestSpfScore = 100,
        ?int $latestDkimScore = 100,
        ?int $latestDmarcScore = 100,
        ?int $latestMxScore = 95,
    ): DnsHealthOverviewResult {
        return new DnsHealthOverviewResult(
            domainId: 'domain-id',
            domainName: 'example.com',
            spfVerifiedAt: $spfVerifiedAt,
            dkimVerifiedAt: $dkimVerifiedAt,
            dmarcVerifiedAt: $dmarcVerifiedAt,
            latestSnapshotGrade: 'A',
            latestSnapshotScore: 95,
            latestSpfScore: $latestSpfScore,
            latestDkimScore: $latestDkimScore,
            latestDmarcScore: $latestDmarcScore,
            latestMxScore: $latestMxScore,
            latestCheckedAt: new \DateTimeImmutable(),
        );
    }
}
