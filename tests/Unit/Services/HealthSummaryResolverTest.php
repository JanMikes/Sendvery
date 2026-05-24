<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\DomainOverviewResult;
use App\Results\DomainVerificationStatusResult;
use App\Services\DomainHealthClassifier;
use App\Services\HealthSummaryResolver;
use App\Value\DomainVerificationSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HealthSummaryResolverTest extends TestCase
{
    private function resolver(): HealthSummaryResolver
    {
        return new HealthSummaryResolver(new DomainHealthClassifier());
    }

    #[Test]
    public function resolveSetupNotFinishedWhenNoDomains(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve(domains: [], verificationStatus: null, verificationSeverity: null);

        self::assertSame('Setup not finished', $result->headline);
        self::assertSame('error', $result->severity);
        self::assertSame(0, $result->domainsTotalCount);
        self::assertSame(0, $result->domainsHealthyCount);
        self::assertSame(0, $result->domainsAttentionCount);
        self::assertSame(0, $result->domainsUnverifiedCount);
    }

    #[Test]
    public function resolveSetupNotFinishedWhenDomainUnverified(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(passRate: 0.0)],
            verificationStatus: $this->buildStatus(dmarcVerifiedAt: null),
            verificationSeverity: DomainVerificationSeverity::Critical,
        );

        self::assertSame('Setup not finished', $result->headline);
        self::assertSame('error', $result->severity);
        self::assertSame(1, $result->domainsTotalCount);
        self::assertSame(1, $result->domainsUnverifiedCount);
    }

    #[Test]
    public function resolveAllHealthyWhenSingleDomainAbove90AndAllProtocolsConfigured(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve(
            domains: [$this->healthyDomain(passRate: 95.0)],
            verificationStatus: $this->buildStatus(dmarcVerifiedAt: new \DateTimeImmutable('-10 days')),
            verificationSeverity: DomainVerificationSeverity::Ok,
        );

        self::assertSame('All domains healthy', $result->headline);
        self::assertSame('success', $result->severity);
        self::assertSame(1, $result->domainsHealthyCount);
        self::assertSame(0, $result->domainsAttentionCount);
    }

    #[Test]
    public function resolveAllHealthyWhenMultipleDomainsAllAbove90AndAllProtocolsConfigured(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve(
            domains: [
                $this->healthyDomain(passRate: 99.0),
                $this->healthyDomain(passRate: 92.5),
                $this->healthyDomain(passRate: 100.0),
            ],
            verificationStatus: null,
            verificationSeverity: null,
        );

        self::assertSame('All domains healthy', $result->headline);
        self::assertSame('success', $result->severity);
        self::assertSame(3, $result->domainsHealthyCount);
        self::assertSame(0, $result->domainsAttentionCount);
        self::assertSame(3, $result->domainsTotalCount);
    }

    #[Test]
    public function resolveOneNeedsAttentionWhenPassRateBelow90(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve(
            domains: [$this->healthyDomain(passRate: 80.0)],
            verificationStatus: $this->buildStatus(dmarcVerifiedAt: new \DateTimeImmutable('-10 days')),
            verificationSeverity: DomainVerificationSeverity::Ok,
        );

        self::assertSame('1 domain needs attention', $result->headline);
        self::assertSame('warning', $result->severity);
        self::assertSame(1, $result->domainsAttentionCount);
        self::assertSame(0, $result->domainsHealthyCount);
    }

    #[Test]
    public function resolveTwoNeedAttentionPlural(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve(
            domains: [
                $this->healthyDomain(passRate: 70.0),
                $this->healthyDomain(passRate: 50.0),
            ],
            verificationStatus: null,
            verificationSeverity: null,
        );

        self::assertSame('2 domains need attention', $result->headline);
        self::assertSame('warning', $result->severity);
        self::assertSame(2, $result->domainsAttentionCount);
    }

    #[Test]
    public function resolveCountsAreCorrect(): void
    {
        $resolver = $this->resolver();

        $result = $resolver->resolve(
            domains: [
                $this->healthyDomain(passRate: 95.0),
                $this->healthyDomain(passRate: 98.0),
                $this->healthyDomain(passRate: 60.0),
            ],
            verificationStatus: null,
            verificationSeverity: null,
        );

        self::assertSame(3, $result->domainsTotalCount);
        self::assertSame(2, $result->domainsHealthyCount);
        self::assertSame(1, $result->domainsAttentionCount);
        self::assertSame(0, $result->domainsUnverifiedCount);
    }

    #[Test]
    public function resolveUnverifiedCountOne(): void
    {
        // Two-domain team: one verified+all-configured+99% (Healthy), one
        // unverified (Unverified). Headline lands in "Setup not finished"
        // because at least one domain isn't yet verified.
        $resolver = $this->resolver();

        $result = $resolver->resolve(
            domains: [
                $this->healthyDomain(passRate: 100.0),
                $this->buildDomain(passRate: 99.0),
            ],
            verificationStatus: $this->buildStatus(dmarcVerifiedAt: null),
            verificationSeverity: DomainVerificationSeverity::Critical,
        );

        self::assertSame(1, $result->domainsUnverifiedCount);
        self::assertSame(0, $result->domainsAttentionCount);
        self::assertSame(1, $result->domainsHealthyCount);
        self::assertSame('error', $result->severity);
        self::assertSame('Setup not finished', $result->headline);
    }

    #[Test]
    public function resolveCountsVerifiedDomainMissingDnsSnapshotAsAttentionNotHealthy(): void
    {
        // TASK-098 unification: a verified domain with a high pass rate but
        // NO joined-in DNS snapshot (latest*Score = null) is NOT Healthy —
        // we don't have enough data to claim "all good". It now lands in
        // Attention, matching what the `/app/domains` list card glyph + the
        // `/app/domains/{id}` banner would show for the same domain.
        $resolver = $this->resolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(passRate: 100.0, dmarcVerifiedAt: '2024-03-15 10:00:00')],
            verificationStatus: null,
            verificationSeverity: null,
        );

        self::assertSame(1, $result->domainsAttentionCount);
        self::assertSame(0, $result->domainsHealthyCount);
        self::assertSame('1 domain needs attention', $result->headline);
    }

    private function buildDomain(
        string $domainId = 'domain-id',
        string $domainName = 'example.com',
        float $passRate = 100.0,
        ?string $dmarcVerifiedAt = null,
    ): DomainOverviewResult {
        return new DomainOverviewResult(
            domainId: $domainId,
            domainName: $domainName,
            totalReports: 0,
            latestReportDate: null,
            passRate: $passRate,
            teamId: 'team-id',
            teamName: 'Team',
            dmarcVerifiedAt: $dmarcVerifiedAt,
        );
    }

    /**
     * Verified domain with all 4 DNS protocols configured — the only shape
     * that can land in Healthy under the TASK-098 unified rule.
     */
    private function healthyDomain(
        string $domainId = 'domain-id',
        string $domainName = 'example.com',
        float $passRate = 100.0,
    ): DomainOverviewResult {
        return new DomainOverviewResult(
            domainId: $domainId.'-'.$passRate,
            domainName: $domainName,
            totalReports: 5,
            latestReportDate: '2024-04-02 00:00:00',
            passRate: $passRate,
            teamId: 'team-id',
            teamName: 'Team',
            dmarcVerifiedAt: '2024-03-15 10:00:00',
            spfVerifiedAt: '2024-03-15 10:00:00',
            dkimVerifiedAt: '2024-03-15 10:00:00',
            latestSpfScore: 100,
            latestDkimScore: 100,
            latestDmarcScore: 100,
            latestMxScore: 95,
        );
    }

    private function buildStatus(
        string $domainId = 'domain-id',
        string $domainName = 'example.com',
        ?\DateTimeImmutable $dmarcVerifiedAt = null,
        ?\DateTimeImmutable $firstReportAt = null,
        int $consecutiveDmarcFailures = 0,
    ): DomainVerificationStatusResult {
        return new DomainVerificationStatusResult(
            domainId: $domainId,
            domainName: $domainName,
            spfVerifiedAt: null,
            dkimVerifiedAt: null,
            dmarcVerifiedAt: $dmarcVerifiedAt,
            firstReportAt: $firstReportAt,
            consecutiveDmarcFailures: $consecutiveDmarcFailures,
        );
    }
}
