<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Results\DomainOverviewResult;
use App\Value\DomainHealthFilter;
use PHPUnit\Framework\TestCase;

final class DomainHealthFilterFromOverviewTest extends TestCase
{
    private function overview(?string $dmarcVerifiedAt, float $passRate, int $totalReports = 1): DomainOverviewResult
    {
        return new DomainOverviewResult(
            domainId: 'domain-id',
            domainName: 'example.com',
            totalReports: $totalReports,
            latestReportDate: $totalReports > 0 ? '2026-05-01 00:00:00' : null,
            passRate: $passRate,
            teamId: 'team-id',
            teamName: 'Team',
            dmarcVerifiedAt: $dmarcVerifiedAt,
        );
    }

    public function testUnverifiedWhenDmarcVerifiedAtIsNull(): void
    {
        $result = $this->overview(dmarcVerifiedAt: null, passRate: 100.0);

        self::assertSame(DomainHealthFilter::Unverified, DomainHealthFilter::fromOverview($result));
    }

    public function testHealthyAtBoundaryPassRateNinety(): void
    {
        $result = $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 90.0);

        self::assertSame(DomainHealthFilter::Healthy, DomainHealthFilter::fromOverview($result));
    }

    public function testHealthyAbovePassRateNinety(): void
    {
        $result = $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 99.9);

        self::assertSame(DomainHealthFilter::Healthy, DomainHealthFilter::fromOverview($result));
    }

    public function testAttentionBelowPassRateNinety(): void
    {
        $result = $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 89.9);

        self::assertSame(DomainHealthFilter::Attention, DomainHealthFilter::fromOverview($result));
    }

    public function testAttentionWhenVerifiedButZeroReports(): void
    {
        // pass_rate = 0 via COALESCE fallback in GetDomainOverview when no
        // records exist — mirrors the query's HAVING < 90 branch for verified
        // domains. The "verified but silent" inbox is a genuine attention case.
        $result = $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 0.0, totalReports: 0);

        self::assertSame(DomainHealthFilter::Attention, DomainHealthFilter::fromOverview($result));
    }

    public function testUnverifiedNotAttentionForBrandNewDomainWithZeroReports(): void
    {
        // Regression guard: a freshly-added domain has dmarcVerifiedAt = null
        // AND zero reports → must classify as Unverified (yellow), not
        // Attention (red). Prevents the new-domain false-alarm.
        $result = $this->overview(dmarcVerifiedAt: null, passRate: 0.0, totalReports: 0);

        self::assertSame(DomainHealthFilter::Unverified, DomainHealthFilter::fromOverview($result));
    }
}
