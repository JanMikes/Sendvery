<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\ReportSenderGroupResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetReportSenderGroups
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds
     *
     * @return list<ReportSenderGroupResult>
     */
    public function forReport(string $reportId, array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<array{group_key: string, display_label: string, total_messages: int|string, dkim_pass_count: int|string, spf_pass_count: int|string, disposition_none: int|string, disposition_quarantine: int|string, disposition_reject: int|string, source_ips: string, sender_is_authorized: int|string|null}> $rows */
        $rows = $this->database->executeQuery(
            "SELECT
                COALESCE(rec.resolved_org, rec.resolved_hostname, rec.source_ip) AS group_key,
                COALESCE(rec.resolved_org, rec.resolved_hostname, rec.source_ip) AS display_label,
                SUM(rec.count) AS total_messages,
                SUM(CASE WHEN rec.dkim_result = 'pass' THEN rec.count ELSE 0 END) AS dkim_pass_count,
                SUM(CASE WHEN rec.spf_result  = 'pass' THEN rec.count ELSE 0 END) AS spf_pass_count,
                SUM(CASE WHEN rec.disposition = 'none' THEN rec.count ELSE 0 END) AS disposition_none,
                SUM(CASE WHEN rec.disposition = 'quarantine' THEN rec.count ELSE 0 END) AS disposition_quarantine,
                SUM(CASE WHEN rec.disposition = 'reject' THEN rec.count ELSE 0 END) AS disposition_reject,
                array_agg(DISTINCT rec.source_ip) AS source_ips,
                MAX(ks.is_authorized::int) AS sender_is_authorized
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            LEFT JOIN known_sender ks
                ON ks.monitored_domain_id = dr.monitored_domain_id
                AND ks.source_ip = rec.source_ip
            WHERE rec.dmarc_report_id = :reportId
              AND md.team_id IN (:teamIds)
            GROUP BY group_key
            ORDER BY total_messages DESC",
            ['reportId' => $reportId, 'teamIds' => $teamIds],
            ['teamIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(ReportSenderGroupResult::fromDatabaseRow(...), $rows);
    }
}
