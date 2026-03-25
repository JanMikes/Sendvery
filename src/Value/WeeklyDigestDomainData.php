<?php

declare(strict_types=1);

namespace App\Value;

final readonly class WeeklyDigestDomainData
{
    /**
     * @param array<string>              $newSenders
     * @param list<array<string, mixed>> $alerts
     */
    public function __construct(
        public string $domainName,
        public int $totalMessages,
        public float $passRate,
        public ?float $passRateDelta,
        public array $newSenders,
        public array $alerts,
    ) {
    }
}
