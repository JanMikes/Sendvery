<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\MonthlyReportUsageRawResult;
use App\Value\Reports\QuarantineReason;
use Doctrine\DBAL\Connection;

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
 */
final readonly class GetMonthlyReportUsage
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forTeam(string $teamId): ?MonthlyReportUsageRawResult
    {
        /** @var array{current_count: int|string, period_ends_at: string, plan_overage_quarantine_count: int|string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                tu.reports_parsed_count AS current_count,
                tu.period_ends_at,
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
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return MonthlyReportUsageRawResult::fromDatabaseRow($row);
    }
}
