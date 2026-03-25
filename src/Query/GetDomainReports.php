<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainReportListResult;
use Doctrine\DBAL\Connection;

final readonly class GetDomainReports
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /** @return array<DomainReportListResult> */
    public function forDomain(string $domainId, int $limit = 50, int $offset = 0): array
    {
        $data = $this->database->executeQuery(
            'SELECT
                dr.id AS report_id,
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
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            WHERE dr.monitored_domain_id = :domainId
            GROUP BY dr.id, dr.reporter_org, dr.date_range_begin, dr.date_range_end
            ORDER BY dr.date_range_end DESC
            LIMIT :limit OFFSET :offset',
            [
                'domainId' => $domainId,
                'pass' => 'pass',
                'limit' => $limit,
                'offset' => $offset,
            ],
        )->fetchAllAssociative();

        return array_map(DomainReportListResult::fromDatabaseRow(...), $data);
    }
}
