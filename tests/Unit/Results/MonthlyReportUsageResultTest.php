<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\MonthlyReportUsageResult;
use PHPUnit\Framework\TestCase;

final class MonthlyReportUsageResultTest extends TestCase
{
    public function testNextTierRetentionUpsellReturnsNullForUnlimitedRetention(): void
    {
        $result = $this->build(retentionDays: null);

        self::assertNull($result->nextTierRetentionUpsell());
    }

    public function testNextTierRetentionUpsellPointsAtPersonalForFreePlan(): void
    {
        $result = $this->build(retentionDays: 30);

        self::assertSame('Upgrade to Personal for 1-year retention →', $result->nextTierRetentionUpsell());
    }

    public function testNextTierRetentionUpsellPointsAtProForPersonalPlan(): void
    {
        $result = $this->build(retentionDays: 365);

        self::assertSame('Upgrade to Pro for 2-year retention →', $result->nextTierRetentionUpsell());
    }

    public function testNextTierRetentionUpsellPointsAtBusinessForProPlan(): void
    {
        $result = $this->build(retentionDays: 730);

        self::assertSame('Upgrade to Business for unlimited retention →', $result->nextTierRetentionUpsell());
    }

    private function build(?int $retentionDays): MonthlyReportUsageResult
    {
        return new MonthlyReportUsageResult(
            currentCount: 50,
            limit: 1000,
            percentageUsed: 5.0,
            periodEndsAt: new \DateTimeImmutable('2026-06-01 00:00:00'),
            planOverageQuarantineCount: 0,
            isUnlimited: false,
            retentionDays: $retentionDays,
        );
    }
}
