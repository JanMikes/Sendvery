<?php

declare(strict_types=1);

namespace App\Value\Reports;

/**
 * URL-driven filter applied to the quarantine list page (`?reason=`). The
 * three cases map 1:1 to the persisted {@see QuarantineReason} enum used on
 * {@see \App\Entity\QuarantinedDmarcReport::$reason}. Kept as a separate type
 * — and not just aliased to `QuarantineReason` — so the controller can
 * `tryFrom()` arbitrary user input without conflating "filter chip state"
 * with "stored row reason"; the All-chip is represented as `null` everywhere
 * the filter is passed around.
 */
enum QuarantineReasonFilter: string
{
    case UnknownDomain = 'unknown_domain';
    case UnverifiedDomain = 'unverified_domain';
    case PlanOverage = 'plan_overage';

    public function toQuarantineReason(): QuarantineReason
    {
        return QuarantineReason::from($this->value);
    }
}
