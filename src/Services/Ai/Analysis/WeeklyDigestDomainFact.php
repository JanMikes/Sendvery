<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * One domain's week, pre-computed and sanitized, for the weekly-digest prompt.
 */
final readonly class WeeklyDigestDomainFact
{
    public function __construct(
        public string $domain,
        public int $messages,
        public float $passRate,
        public ?float $passRateDelta,
        public int $newSenderCount,
        public int $alertCount,
    ) {
    }
}
