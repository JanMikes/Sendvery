<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\MonthlyReportUsageRawResult;
use App\Value\Reports\QuarantineReason;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Reads the team_usage row for a team plus a count of any DMARC reports we
 * had to quarantine because the team hit its monthly plan cap. The
 * PlanOverage count surfaces as the "N reports waiting" warning on the
 * billing page — a customer can have reports parked without knowing it
 * unless they bump into the (deferred) usage-warning email.
 *
 * Returns null when the team has no team_usage row yet (i.e. has never had a
 * report parsed). Both callers treat that as "nothing to show".
 *
 * Single round-trip: the quarantine count is a scalar subquery joined to the
 * monitored_domain table on `LOWER(md.domain) = qdr.domain_name`, matching
 * the existing convention in QuarantinedDmarcReportRepository::countForDomain.
 *
 * PERIOD FRESHNESS — `team_usage` holds ONE mutable row per team, so a counter
 * from a finished month is indistinguishable from a current one except by
 * reading `period_ends_at`. The row is rolled forward by two writers:
 * {@see \App\Services\Stripe\PlanEnforcement::ensureCurrentPeriod()} lazily on
 * every enforcement read, and the `0 0 * * *` `sendvery:usage:reset` cron for
 * idle teams. Neither has necessarily run when this page renders — so this
 * query applies the same rule read-only rather than trusting the stored row.
 *
 * WHY it matters: before this, a team whose usage had actually rolled over saw
 * last month's figure on `/app/billing` ("940 / 1,000", 94%, red) next to an AI
 * quota that HAD reset to 0 — the same page contradicting itself — plus
 * "Resets <a date in the past>", and an unearned red "Reports this month" upsell
 * card on `/app`. Enforcement was never wrong (that path is self-healing); only
 * the figures the user reads were.
 */
final readonly class GetMonthlyReportUsage
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function forTeam(string $teamId): ?MonthlyReportUsageRawResult
    {
        // `<=` and "first day of this month + 1 month" mirror
        // PlanEnforcement::ensureCurrentPeriod() exactly, so the number shown
        // and the number enforced can never disagree.
        $now = $this->clock->now();

        /** @var array{current_count: int|string, period_ends_at: string, plan_overage_quarantine_count: int|string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                CASE WHEN tu.period_ends_at <= :now
                    THEN 0
                    ELSE tu.reports_parsed_count
                END AS current_count,
                CASE WHEN tu.period_ends_at <= :now
                    THEN date_trunc(\'month\', :now::timestamp) + INTERVAL \'1 month\'
                    ELSE tu.period_ends_at
                END AS period_ends_at,
                (
                    SELECT COUNT(*)
                    FROM quarantined_dmarc_report qdr
                    JOIN monitored_domain md ON LOWER(md.domain) = qdr.domain_name
                    WHERE md.team_id = :teamId AND qdr.reason = :overageReason
                ) AS plan_overage_quarantine_count
            FROM team_usage tu
            WHERE tu.team_id = :teamId',
            [
                'teamId' => $teamId,
                'overageReason' => QuarantineReason::PlanOverage->value,
                'now' => $now->format('Y-m-d H:i:s'),
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return MonthlyReportUsageRawResult::fromDatabaseRow($row);
    }
}
