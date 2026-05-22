<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Raw row from `team_usage` plus the joined PlanOverage quarantine count.
 *
 * This is the database-layer DTO returned by GetMonthlyReportUsage. The
 * controller then folds it together with PlanLimits-derived values
 * (limit, percentage, retention) into the richer MonthlyReportUsageResult
 * before handing it to templates.
 */
final readonly class MonthlyReportUsageRawResult
{
    public function __construct(
        public int $currentCount,
        public \DateTimeImmutable $periodEndsAt,
        public int $planOverageQuarantineCount,
    ) {
    }

    /**
     * @param array{
     *     current_count: int|string,
     *     period_ends_at: string,
     *     plan_overage_quarantine_count: int|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            currentCount: (int) $row['current_count'],
            periodEndsAt: new \DateTimeImmutable($row['period_ends_at']),
            planOverageQuarantineCount: (int) $row['plan_overage_quarantine_count'],
        );
    }
}
