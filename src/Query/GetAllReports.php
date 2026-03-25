<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\ReportListResult;
use Doctrine\DBAL\Connection;

final readonly class GetAllReports
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /** @return array<ReportListResult> */
    public function forTeam(string $teamId, int $limit = 50, int $offset = 0): array
    {
        /** @var list<array{report_id: string, domain_name: string, reporter_org: string, date_range_begin: string, date_range_end: string, record_count: int|string, pass_rate: float|string}> $data */
        $data = $this->database->executeQuery(
            'SELECT
                dr.id AS report_id,
                md.domain AS domain_name,
                dr.reporter_org AS reporter_org,
                dr.date_range_begin AS date_range_begin,
                dr.date_range_end AS date_range_end,
                COUNT(rec.id) AS record_count,
                COALESCE(
                    SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                    / NULLIF(SUM(rec.count), 0)
                    * 100,
                    0
                ) AS pass_rate
            FROM dmarc_report dr
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            WHERE md.team_id = :teamId
            GROUP BY dr.id, md.domain, dr.reporter_org, dr.date_range_begin, dr.date_range_end
            ORDER BY dr.date_range_end DESC
            LIMIT :limit OFFSET :offset',
            [
                'teamId' => $teamId,
                'pass' => 'pass',
                'limit' => $limit,
                'offset' => $offset,
            ],
        )->fetchAllAssociative();

        return array_map(ReportListResult::fromDatabaseRow(...), $data);
    }
}
