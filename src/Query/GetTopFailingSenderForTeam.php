<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\TopFailingSenderResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Returns the single biggest contributor to DMARC failures in the team's
 * last 7 days. Feeds the "X% of the failures came from {{ sender }}"
 * sentence in the {@see \App\Twig\Components\PassRateRegressionBanner}
 * (TASK-093). Aggregates {@see \App\Entity\DmarcRecord} rows the same way
 * {@see GetTopSendersForDomain} does, but at team scope and only counting
 * records that failed DMARC alignment (DKIM AND SPF both not pass).
 *
 * Returns null when nothing failed (no banner to populate) or when the team
 * has no reports in the window. The "monitored_domain_id" is included so the
 * banner can deep-link directly to the right /app/domains/{id}/senders page.
 */
final readonly class GetTopFailingSenderForTeam
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $teamIds
     */
    public function forTeams(array $teamIds): ?TopFailingSenderResult
    {
        if ([] === $teamIds) {
            return null;
        }

        $now = $this->clock->now();
        $sevenDaysAgo = $now->modify('-7 days');

        /** @var array{sender_id: string|null, display_label: string, source_ip: string, monitored_domain_id: string, failing_message_count: int|string}|false $row */
        $row = $this->database->executeQuery(
            "SELECT
                MAX(ks.id::text) AS sender_id,
                COALESCE(rec.resolved_org, rec.resolved_hostname, rec.source_ip) AS display_label,
                rec.source_ip,
                dr.monitored_domain_id,
                SUM(rec.count) AS failing_message_count
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            LEFT JOIN known_sender ks
                ON ks.monitored_domain_id = dr.monitored_domain_id
                AND ks.source_ip = rec.source_ip
            WHERE md.team_id IN (:teamIds)
              AND dr.date_range_end >= :from
              AND rec.dkim_result <> 'pass'
              AND rec.spf_result <> 'pass'
            GROUP BY rec.source_ip, dr.monitored_domain_id, display_label
            ORDER BY failing_message_count DESC
            LIMIT 1",
            [
                'teamIds' => $teamIds,
                'from' => $sevenDaysAgo->format('Y-m-d H:i:s'),
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return TopFailingSenderResult::fromDatabaseRow($row);
    }
}
