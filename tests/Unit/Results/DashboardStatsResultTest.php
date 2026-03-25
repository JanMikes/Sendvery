<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DashboardStatsResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DashboardStatsResultTest extends TestCase
{
    #[Test]
    public function itCanBeConstructed(): void
    {
        $result = new DashboardStatsResult(
            totalDomains: 5,
            totalReportsLast30Days: 42,
            overallPassRate: 95.5,
            totalMessages: 1234,
        );

        self::assertSame(5, $result->totalDomains);
        self::assertSame(42, $result->totalReportsLast30Days);
        self::assertSame(95.5, $result->overallPassRate);
        self::assertSame(1234, $result->totalMessages);
    }
}
