<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainSenderAuthorizationSummary;
use App\Results\TopSenderForDomainResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Domain-scoped "Top Senders" data for the {@see \App\Controller\Dashboard\ShowDomainDetailController}.
 *
 * Aggregates {@see \App\Entity\DmarcRecord} rows across all reports for one
 * domain and joins {@see \App\Entity\KnownSender} so the chart can colour
 * authorised IPs differently from unknown ones — the single most actionable
 * insight a DMARC report can surface ("Mailchimp sends 40% of your mail and
 * 8% of it fails DKIM").
 */
final readonly class GetTopSendersForDomain
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return list<TopSenderForDomainResult>
     */
    public function forDomain(string $domainId, array $teamIds, int $limit = 5): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<array{group_key: string, display_label: string, total_messages: int|string, dkim_pass_count: int|string, spf_pass_count: int|string, known_sender_id: string|null, sender_is_authorized: int|string|bool|null}> $rows */
        $rows = $this->database->executeQuery(
            "SELECT
                COALESCE(rec.resolved_org, rec.resolved_hostname, rec.source_ip) AS group_key,
                COALESCE(rec.resolved_org, rec.resolved_hostname, rec.source_ip) AS display_label,
                SUM(rec.count) AS total_messages,
                SUM(CASE WHEN rec.dkim_result = 'pass' THEN rec.count ELSE 0 END) AS dkim_pass_count,
                SUM(CASE WHEN rec.spf_result  = 'pass' THEN rec.count ELSE 0 END) AS spf_pass_count,
                MAX(ks.id::text) AS known_sender_id,
                MAX(ks.is_authorized::int) AS sender_is_authorized
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            LEFT JOIN known_sender ks
                ON ks.monitored_domain_id = dr.monitored_domain_id
                AND ks.source_ip = rec.source_ip
            WHERE dr.monitored_domain_id = :domainId
              AND md.team_id IN (:teamIds)
            GROUP BY group_key
            ORDER BY total_messages DESC
            LIMIT :limit",
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
                'limit' => $limit,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(TopSenderForDomainResult::fromDatabaseRow(...), $rows);
    }

    /**
     * Headline counts for the stat row above the chart. Reads from
     * {@see \App\Entity\KnownSender} (not DMARC records) so the "X unique IPs"
     * count mirrors what the user sees on the Sender Inventory page — same
     * source-of-truth, no row-count drift.
     *
     * @param list<string> $teamIds
     */
    public function summaryForDomain(string $domainId, array $teamIds): DomainSenderAuthorizationSummary
    {
        if ([] === $teamIds) {
            return new DomainSenderAuthorizationSummary(0, 0, 0);
        }

        // SQL aggregates always return one row (even when WHERE matches
        // nothing), so we assert non-false to satisfy PHPStan without an
        // unreachable `false === $row` branch.
        /** @var array{authorized_count: int|string, unknown_count: int|string, unique_ip_count: int|string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                COUNT(*) FILTER (WHERE ks.is_authorized) AS authorized_count,
                COUNT(*) FILTER (WHERE NOT ks.is_authorized) AS unknown_count,
                COUNT(DISTINCT ks.source_ip) AS unique_ip_count
            FROM known_sender ks
            JOIN monitored_domain md ON md.id = ks.monitored_domain_id
            WHERE ks.monitored_domain_id = :domainId
              AND md.team_id IN (:teamIds)',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        assert(false !== $row);

        return DomainSenderAuthorizationSummary::fromDatabaseRow($row);
    }
}
