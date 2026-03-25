<?php

declare(strict_types=1);

namespace App\Results;

readonly final class DashboardStatsResult
{
    public function __construct(
        public int $totalDomains,
        public int $totalReportsLast30Days,
        public float $overallPassRate,
        public int $totalMessages,
    ) {
    }
}
