<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Composite DTO used by the billing page (always rendered when team_usage
 * exists) and the dashboard overview (rendered as a 6th stat card when the
 * team has crossed 50% usage on a finite-limit plan).
 *
 * `retentionDays === null` means unlimited retention (Business / Unlimited).
 * `isUnlimited === true` means unlimited monthly report ingestion (only the
 * staff-grant Unlimited tier today; `PlanLimits::getMaxReportsPerMonth()`
 * returns PHP_INT_MAX for it).
 */
final readonly class MonthlyReportUsageResult
{
    public function __construct(
        public int $currentCount,
        public int $limit,
        public float $percentageUsed,
        public \DateTimeImmutable $periodEndsAt,
        public int $planOverageQuarantineCount,
        public bool $isUnlimited,
        public ?int $retentionDays,
    ) {
    }

    /**
     * Plain-English upsell line for plans below their next retention step.
     * Returns null when the plan already has unlimited retention (Business+)
     * or when the retention value doesn't map to a known tier — the latter
     * is defensive against future PlanLimits changes that don't update this
     * mapping in lockstep.
     */
    public function nextTierRetentionUpsell(): ?string
    {
        return match ($this->retentionDays) {
            null => null,
            30 => 'Upgrade to Personal for 1-year retention →',
            365 => 'Upgrade to Pro for 2-year retention →',
            730 => 'Upgrade to Business for unlimited retention →',
            default => null,
        };
    }
}
