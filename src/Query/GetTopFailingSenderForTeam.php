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
 *
 * The role travels with the sender because the banner's whole job is to say
 * what to do next, and "the biggest source of failures is a mail gateway
 * forwarding your mail" needs a very different reaction from the same sentence
 * about an unrecognised host.
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

        // Grouped by sender identity, not by address: a gateway that spreads its
        // failures over three continental nodes was previously credited with a
        // third of the damage each, so the banner named — and sent the reader
        // after — whichever node happened to be largest. MIN(source_ip) picks
        // one member deterministically for the "which address" line; the
        // identity, not that address, is what the sentence is about.
        /** @var array{sender_id: string|null, display_label: string, sender_role: string|null, source_ip: string, monitored_domain_id: string, failing_message_count: int|string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                MAX(ks.id::text) AS sender_id,
                '.SenderIdentitySql::GROUPED_DISPLAY_LABEL.' AS display_label,
                '.SenderIdentitySql::GROUPED_ROLE.' AS sender_role,
                MIN(rec.source_ip) AS source_ip,
                dr.monitored_domain_id,
                SUM(rec.count) AS failing_message_count
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            LEFT JOIN known_sender ks
                ON ks.monitored_domain_id = dr.monitored_domain_id
                AND ks.source_ip = rec.source_ip
            '.SenderIdentitySql::JOIN."
            WHERE md.team_id IN (:teamIds)
              AND dr.date_range_end >= :from
              AND rec.dkim_result <> 'pass'
              AND rec.spf_result <> 'pass'
            GROUP BY ".SenderIdentitySql::IDENTITY_KEY.', dr.monitored_domain_id
            ORDER BY failing_message_count DESC
            LIMIT 1',
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
