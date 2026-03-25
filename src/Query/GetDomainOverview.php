<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainOverviewResult;
use Doctrine\DBAL\Connection;

readonly final class GetDomainOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /** @return array<DomainOverviewResult> */
    public function forTeam(string $teamId): array
    {
        $data = $this->database->executeQuery(
            'SELECT
                md.id AS domain_id,
                md.domain AS domain_name,
                COUNT(dr.id) AS total_reports,
                MAX(dr.date_range_end) AS latest_report_date,
                COALESCE(
                    SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                    / NULLIF(SUM(rec.count), 0)
                    * 100,
                    0
                ) AS pass_rate
            FROM monitored_domain md
            LEFT JOIN dmarc_report dr ON dr.monitored_domain_id = md.id
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            WHERE md.team_id = :teamId
            GROUP BY md.id, md.domain
            ORDER BY md.domain ASC',
            [
                'teamId' => $teamId,
                'pass' => 'pass',
            ],
        )->fetchAllAssociative();

        return array_map(DomainOverviewResult::fromDatabaseRow(...), $data);
    }
}
