<?php

declare(strict_types=1);

namespace App\Results;

/**
 * One-line health headline for the dashboard overview banner. Aggregates
 * per-domain pass-rate and verification state into a single severity tone
 * (success / warning / error) plus matching counts.
 */
final readonly class HealthSummaryResult
{
    public function __construct(
        public string $headline,
        public string $severity,
        public int $domainsHealthyCount,
        public int $domainsAttentionCount,
        public int $domainsUnverifiedCount,
        public int $domainsTotalCount,
    ) {
    }
}
