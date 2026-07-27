<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\ReleasableQuarantinedReportResult;
use App\Value\Reports\QuarantineReason;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Finds `plan_overage` quarantine rows that can go back into the report
 * pipeline now that the owning team has monthly headroom again.
 *
 * Only rows whose domain is monitored AND verified are returned — releasing
 * into an unverified domain would contradict {@see \App\Services\Reports\DmarcReportRouter},
 * which is what parked unverified domains in the first place. The join is
 * `LOWER(md.domain) = qdr.domain_name`, matching the convention in
 * QuarantinedDmarcReportRepository and GetMonthlyReportUsage.
 *
 * Oldest first: a backlog bigger than one period's cap drains in arrival order
 * across periods instead of stranding the earliest reports forever.
 */
final readonly class GetReleasableQuarantinedReports
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param int $limit the team's remaining monthly report allowance — never
     *                   release more than the cap allows, or the ingestion gate
     *                   parks them straight back
     *
     * @return list<ReleasableQuarantinedReportResult>
     */
    public function overCapForTeam(string $teamId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        /** @var list<array{quarantine_id: string, domain_id: string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT qdr.id AS quarantine_id, md.id AS domain_id
             FROM quarantined_dmarc_report qdr
             JOIN monitored_domain md ON LOWER(md.domain) = qdr.domain_name
             WHERE md.team_id = :teamId
               AND md.dmarc_verified_at IS NOT NULL
               AND qdr.reason = :overageReason
             ORDER BY qdr.quarantined_at ASC
             LIMIT :limit',
            [
                'teamId' => $teamId,
                'overageReason' => QuarantineReason::PlanOverage->value,
                'limit' => $limit,
            ],
            [
                'limit' => ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        return array_map(ReleasableQuarantinedReportResult::fromDatabaseRow(...), $rows);
    }

    /**
     * Teams holding at least one plan-overage report, whatever their headroom.
     *
     * Used by the `sendvery:usage:reset` cron to fan out release attempts the
     * moment monthly counters roll. Headroom is re-checked per team by the
     * handler, so this list can be generous — the alternative (never asking)
     * is what left over-cap reports parked forever for teams that never upgrade.
     *
     * @return list<string>
     */
    public function teamIdsWithOverCapReports(): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->executeQuery(
            'SELECT DISTINCT md.team_id
             FROM quarantined_dmarc_report qdr
             JOIN monitored_domain md ON LOWER(md.domain) = qdr.domain_name
             WHERE md.dmarc_verified_at IS NOT NULL
               AND qdr.reason = :overageReason',
            ['overageReason' => QuarantineReason::PlanOverage->value],
        )->fetchFirstColumn();

        return $ids;
    }
}
