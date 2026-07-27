<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\ReportSenderGroupResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The "By sender" pane on report detail.
 *
 * Groups by sender identity ({@see SenderIdentitySql}) rather than by IP, which
 * is what collapses `eu.`/`ca.`/`us.cloud-sec-av.com` into the one gateway they
 * actually are — and carries the identity's role, so a pane full of SPF
 * failures can say "forwarder" instead of leaving the reader to assume attack.
 */
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

        /** @var list<array{group_key: string, display_label: string, sender_role: string|null, total_messages: int|string, dkim_pass_count: int|string, spf_pass_count: int|string, disposition_none: int|string, disposition_quarantine: int|string, disposition_reject: int|string, source_ips: string, sender_is_authorized: int|string|null, known_sender_count: int|string, needs_review_sender_count: int|string, authorized_sender_count: int|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT
                '.SenderIdentitySql::IDENTITY_KEY.' AS group_key,
                '.SenderIdentitySql::GROUPED_DISPLAY_LABEL.' AS display_label,
                '.SenderIdentitySql::GROUPED_ROLE." AS sender_role,
                SUM(rec.count) AS total_messages,
                SUM(CASE WHEN rec.dkim_result = 'pass' THEN rec.count ELSE 0 END) AS dkim_pass_count,
                SUM(CASE WHEN rec.spf_result  = 'pass' THEN rec.count ELSE 0 END) AS spf_pass_count,
                SUM(CASE WHEN rec.disposition = 'none' THEN rec.count ELSE 0 END) AS disposition_none,
                SUM(CASE WHEN rec.disposition = 'quarantine' THEN rec.count ELSE 0 END) AS disposition_quarantine,
                SUM(CASE WHEN rec.disposition = 'reject' THEN rec.count ELSE 0 END) AS disposition_reject,
                array_agg(DISTINCT rec.source_ip) AS source_ips,
                MAX(ks.is_authorized::int) AS sender_is_authorized,
                -- A group is an organisation, so it can span several inventory
                -- rows in different states. MAX(is_authorized) alone reported a
                -- whole provider as Authorized the moment ONE of its machines
                -- was, and it cannot express \"never reviewed\" at all. These
                -- three counts let the DTO pick the worst state in the group,
                -- the same way GetTopSendersForDomain does.
                COUNT(DISTINCT ks.source_ip) AS known_sender_count,
                COUNT(DISTINCT ks.source_ip) FILTER (WHERE NOT ks.is_authorized AND ks.updated_at IS NULL) AS needs_review_sender_count,
                COUNT(DISTINCT ks.source_ip) FILTER (WHERE ks.is_authorized) AS authorized_sender_count
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            LEFT JOIN known_sender ks
                ON ks.monitored_domain_id = dr.monitored_domain_id
                AND ks.source_ip = rec.source_ip
            ".SenderIdentitySql::JOIN.'
            WHERE rec.dmarc_report_id = :reportId
              AND md.team_id IN (:teamIds)
            GROUP BY group_key
            ORDER BY total_messages DESC',
            ['reportId' => $reportId, 'teamIds' => $teamIds],
            ['teamIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(ReportSenderGroupResult::fromDatabaseRow(...), $rows);
    }
}
