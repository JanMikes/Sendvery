<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainDetailResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetDomainDetail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function forDomain(string $domainId, array $teamIds): ?DomainDetailResult
    {
        if ([] === $teamIds) {
            return null;
        }


        /** @var array{domain_id: string, domain_name: string, dmarc_policy: string|null, spf_verified_at: string|null, dkim_verified_at: string|null, dmarc_verified_at: string|null, first_report_at: string|null, created_at: string, total_reports: int|string, total_messages: int|string, pass_rate: float|string, unique_senders: int|string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                md.id AS domain_id,
                md.domain AS domain_name,
                md.dmarc_policy AS dmarc_policy,
                md.spf_verified_at AS spf_verified_at,
                md.dkim_verified_at AS dkim_verified_at,
                md.dmarc_verified_at AS dmarc_verified_at,
                md.first_report_at AS first_report_at,
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
            WHERE md.id = :domainId AND md.team_id IN (:teamIds)',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
                'pass' => 'pass',
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return DomainDetailResult::fromDatabaseRow($row);
    }
}
