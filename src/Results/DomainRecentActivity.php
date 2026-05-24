<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Trailing-window snapshot of a domain's DMARC activity. Used by the
 * {@see \App\Services\DmarcPolicyAdvisor} so its eligibility logic measures
 * the same population for both the report-count gate and the pass-rate gate.
 * The lifetime pass rate on {@see DomainDetailResult} mixes old and recent
 * sending posture and is the wrong input for "are we ready to escalate?".
 */
final readonly class DomainRecentActivity
{
    public function __construct(
        public int $reportsCount,
        public float $passRate,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0.0);
    }
}
