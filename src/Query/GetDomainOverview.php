<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainOverviewResult;
use App\Value\DomainHealthFilter;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetDomainOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<DomainOverviewResult>
     */
    public function forTeams(array $teamIds, ?DomainHealthFilter $statusFilter = null): array
    {
        if ([] === $teamIds) {
            return [];
        }

        // Compose conditional WHERE/HAVING fragments per filter:
        //   - null         → no fragments, returns every domain
        //   - Unverified   → WHERE dmarc_verified_at IS NULL,                no HAVING
        //   - Healthy      → no extra WHERE,                                 HAVING pass_rate >= 90
        //   - Attention    → WHERE dmarc_verified_at IS NOT NULL,            HAVING pass_rate < 90
        // A verified domain with zero reports gets pass_rate = 0 (COALESCE fallback) → Attention. Intentional.
        $whereClause = '';
        $havingClause = '';
        if (DomainHealthFilter::Unverified === $statusFilter) {
            $whereClause = ' AND md.dmarc_verified_at IS NULL';
        } elseif (DomainHealthFilter::Healthy === $statusFilter) {
            $havingClause = ' HAVING COALESCE(SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float / NULLIF(SUM(rec.count), 0) * 100, 0) >= 90';
        } elseif (DomainHealthFilter::Attention === $statusFilter) {
            $whereClause = ' AND md.dmarc_verified_at IS NOT NULL';
            $havingClause = ' HAVING COALESCE(SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float / NULLIF(SUM(rec.count), 0) * 100, 0) < 90';
        }

        /** @var list<array{domain_id: string, domain_name: string, total_reports: int|string, latest_report_date: string|null, pass_rate: float|string, team_id: string, team_name: string}> $data */
        $data = $this->database->executeQuery(
            'SELECT
                md.id AS domain_id,
                md.domain AS domain_name,
                t.id::text AS team_id,
                t.name AS team_name,
                COUNT(dr.id) AS total_reports,
                MAX(dr.date_range_end) AS latest_report_date,
                COALESCE(
                    SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                    / NULLIF(SUM(rec.count), 0)
                    * 100,
                    0
                ) AS pass_rate
            FROM monitored_domain md
            JOIN team t ON t.id = md.team_id
            LEFT JOIN dmarc_report dr ON dr.monitored_domain_id = md.id
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            WHERE md.team_id IN (:teamIds)'.$whereClause.'
            GROUP BY md.id, md.domain, t.id, t.name'.$havingClause.'
            ORDER BY md.domain ASC',
            [
                'teamIds' => $teamIds,
                'pass' => 'pass',
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(DomainOverviewResult::fromDatabaseRow(...), $data);
    }

    /**
     * Unfiltered domain count for the team scope — used by the domains list
     * empty-state branch to distinguish "no domains at all" from "no domains
     * match the current filter".
     *
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function countForTeams(array $teamIds): int
    {
        if ([] === $teamIds) {
            return 0;
        }

        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM monitored_domain WHERE team_id IN (:teamIds)',
            [
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchOne();
    }
}
