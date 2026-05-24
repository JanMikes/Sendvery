<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\DomainOverviewResult;
use App\Results\DomainVerificationStatusResult;
use App\Services\HealthSummaryResolver;
use App\Value\DomainVerificationSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HealthSummaryResolverTest extends TestCase
{
    #[Test]
    public function resolveSetupNotFinishedWhenNoDomains(): void
    {
        $resolver = new HealthSummaryResolver();

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
        $resolver = new HealthSummaryResolver();

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
    public function resolveAllHealthyWhenSingleDomainAbove90(): void
    {
        $resolver = new HealthSummaryResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(passRate: 95.0)],
            verificationStatus: $this->buildStatus(dmarcVerifiedAt: new \DateTimeImmutable('-10 days')),
            verificationSeverity: DomainVerificationSeverity::Ok,
        );

        self::assertSame('All domains healthy', $result->headline);
        self::assertSame('success', $result->severity);
        self::assertSame(1, $result->domainsHealthyCount);
        self::assertSame(0, $result->domainsAttentionCount);
    }

    #[Test]
    public function resolveAllHealthyWhenMultipleDomainsAllAbove90(): void
    {
        $resolver = new HealthSummaryResolver();

        $result = $resolver->resolve(
            domains: [
                $this->buildDomain(passRate: 99.0),
                $this->buildDomain(passRate: 92.5),
                $this->buildDomain(passRate: 100.0),
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
        $resolver = new HealthSummaryResolver();

        $result = $resolver->resolve(
            domains: [$this->buildDomain(passRate: 80.0)],
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
        $resolver = new HealthSummaryResolver();

        $result = $resolver->resolve(
            domains: [
                $this->buildDomain(passRate: 70.0),
                $this->buildDomain(passRate: 50.0),
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
        $resolver = new HealthSummaryResolver();

        $result = $resolver->resolve(
            domains: [
                $this->buildDomain(passRate: 95.0),
                $this->buildDomain(passRate: 98.0),
                $this->buildDomain(passRate: 60.0),
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
        // Two-domain team where neither is in the "<90% pass rate" attention bucket,
        // but one is still unverified. We can't say "all healthy" — fall through to
        // the catch-all "Setup not finished" tone.
        $resolver = new HealthSummaryResolver();

        $result = $resolver->resolve(
            domains: [
                $this->buildDomain(passRate: 100.0),
                $this->buildDomain(passRate: 99.0),
            ],
            verificationStatus: $this->buildStatus(dmarcVerifiedAt: null),
            verificationSeverity: DomainVerificationSeverity::Critical,
        );

        self::assertSame(1, $result->domainsUnverifiedCount);
        self::assertSame(0, $result->domainsAttentionCount);
        self::assertSame('error', $result->severity);
        self::assertSame('Setup not finished', $result->headline);
    }

    private function buildDomain(
        string $domainId = 'domain-id',
        string $domainName = 'example.com',
        float $passRate = 100.0,
    ): DomainOverviewResult {
        return new DomainOverviewResult(
            domainId: $domainId,
            domainName: $domainName,
            totalReports: 0,
            latestReportDate: null,
            passRate: $passRate,
            teamId: 'team-id',
            teamName: 'Team',
            dmarcVerifiedAt: null,
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
