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
        /**
         * Null when no DMARC records landed in the window. Serialised into the
         * prompt as `null` so the model narrates "no reports yet" instead of
         * inventing a 0% failure.
         */
        public ?float $passRate,
        public ?float $passRateDelta,
        public int $newSenderCount,
        public int $alertCount,
    ) {
    }
}
