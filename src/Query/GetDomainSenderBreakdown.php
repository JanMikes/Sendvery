<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainSenderResult;
use Doctrine\DBAL\Connection;

final readonly class GetDomainSenderBreakdown
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /** @return array<DomainSenderResult> */
    public function forDomain(string $domainId, int $limit = 10): array
    {
        /** @var list<array{source_ip: string, resolved_org: string|null, total_messages: int|string, pass_count: int|string, fail_count: int|string}> $data */
        $data = $this->database->executeQuery(
            'SELECT
                rec.source_ip AS source_ip,
                MAX(rec.resolved_org) AS resolved_org,
                SUM(rec.count) AS total_messages,
                SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END) AS pass_count,
                SUM(CASE WHEN rec.dkim_result != :pass AND rec.spf_result != :pass THEN rec.count ELSE 0 END) AS fail_count
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            WHERE dr.monitored_domain_id = :domainId
            GROUP BY rec.source_ip
            ORDER BY total_messages DESC
            LIMIT :limit',
            [
                'domainId' => $domainId,
                'pass' => 'pass',
                'limit' => $limit,
            ],
        )->fetchAllAssociative();

        return array_map(DomainSenderResult::fromDatabaseRow(...), $data);
    }
}
