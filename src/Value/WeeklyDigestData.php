<?php

declare(strict_types=1);

namespace App\Value;

final readonly class WeeklyDigestData
{
    /**
     * @param array<WeeklyDigestDomainData> $domains
     */
    public function __construct(
        public string $teamName,
        public \DateTimeImmutable $periodStart,
        public \DateTimeImmutable $periodEnd,
        public array $domains,
        public int $totalDomains,
        public int $totalMessages,
        public float $averagePassRate,
        public int $alertsCount,
        public int $dnsChangesCount,
    ) {
    }
}
