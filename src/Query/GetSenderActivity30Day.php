<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\SenderActivity30Day;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Batched 30-day per-sender DMARC volume + DKIM pass rate for the Sender
 * Inventory page (TASK-092). One query per page render — keyed by source IP
 * because that's the join key between {@see \App\Entity\KnownSender} and
 * {@see \App\Entity\DmarcRecord}. The {@see \App\Services\SenderAuthorizationAdvisor}
 * uses this to decide whether to surface a "make a decision" callout on the
 * matching row.
 *
 * Mirrors the {@see GetMailboxDetail::summaryForMailboxes} batched-load shape
 * so the page stays linear in the number of known senders.
 */
final readonly class GetSenderActivity30Day
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $sourceIps source IPs to load activity for; pulled from
     *                                the {@see \App\Results\SenderInventoryResult}
     *                                rows the controller already has on hand
     *
     * @return array<string, SenderActivity30Day> keyed by source IP — missing keys
     *                                            mean zero activity (caller falls
     *                                            back to {@see SenderActivity30Day::empty()})
     */
    public function forDomain(string $domainId, array $sourceIps): array
    {
        if ([] === $sourceIps) {
            return [];
        }

        /** @var list<array{source_ip: string, total_messages_30d: int|string, dkim_pass_count_30d: int|string}> $rows */
        $rows = $this->database->executeQuery(
            "SELECT
                rec.source_ip,
                SUM(rec.count) AS total_messages_30d,
                SUM(CASE WHEN rec.dkim_result = 'pass' THEN rec.count ELSE 0 END) AS dkim_pass_count_30d
            FROM dmarc_record rec
            JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
            WHERE dr.monitored_domain_id = :domainId
              AND dr.date_range_end >= NOW() - INTERVAL '30 days'
              AND rec.source_ip IN (:sourceIps)
            GROUP BY rec.source_ip",
            [
                'domainId' => $domainId,
                'sourceIps' => $sourceIps,
            ],
            [
                'sourceIps' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $byIp = [];
        foreach ($rows as $row) {
            $byIp[$row['source_ip']] = SenderActivity30Day::fromDatabaseRow($row);
        }

        return $byIp;
    }
}
