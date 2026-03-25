<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainDetailResult;
use Doctrine\DBAL\Connection;

final readonly class GetDomainDetail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forDomain(string $domainId): ?DomainDetailResult
    {
        /** @var array{domain_id: string, domain_name: string, dmarc_policy: string|null, is_verified: bool|string, created_at: string, total_reports: int|string, total_messages: int|string, pass_rate: float|string, unique_senders: int|string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                md.id AS domain_id,
                md.domain AS domain_name,
                md.dmarc_policy AS dmarc_policy,
                md.is_verified AS is_verified,
                md.created_at AS created_at,
                COALESCE((SELECT COUNT(*) FROM dmarc_report dr WHERE dr.monitored_domain_id = md.id), 0) AS total_reports,
                COALESCE((
                    SELECT SUM(rec.count)
                    FROM dmarc_record rec
                    JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
                    WHERE dr.monitored_domain_id = md.id
                ), 0) AS total_messages,
                COALESCE((
                    SELECT
                        SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                        / NULLIF(SUM(rec.count), 0)
                        * 100
                    FROM dmarc_record rec
                    JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
                    WHERE dr.monitored_domain_id = md.id
                ), 0) AS pass_rate,
                COALESCE((
                    SELECT COUNT(DISTINCT rec.source_ip)
                    FROM dmarc_record rec
                    JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
                    WHERE dr.monitored_domain_id = md.id
                ), 0) AS unique_senders
            FROM monitored_domain md
            WHERE md.id = :domainId',
            [
                'domainId' => $domainId,
                'pass' => 'pass',
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return DomainDetailResult::fromDatabaseRow($row);
    }
}
