<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Aggregated sender data for the "Top Senders" card on the domain detail page.
 *
 * Mirrors the shape of {@see ReportSenderGroupResult} but aggregates across every
 * DMARC report for a domain (not per-report) so the user sees Mailchimp's
 * cumulative volume — not just one Friday's batch.
 */
final readonly class TopSenderForDomainResult
{
    public function __construct(
        public string $groupKey,
        public string $displayLabel,
        public int $totalMessages,
        public int $dkimPassCount,
        public float $dkimPassRate,
        public int $spfPassCount,
        public float $spfPassRate,
        public ?string $knownSenderId,
        public ?bool $senderIsAuthorized,
    ) {
    }

    /**
     * @param array{group_key: string, display_label: string, total_messages: int|string, dkim_pass_count: int|string, spf_pass_count: int|string, known_sender_id: string|null, sender_is_authorized: int|string|bool|null} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $totalMessages = (int) $row['total_messages'];
        $dkimPassCount = (int) $row['dkim_pass_count'];
        $spfPassCount = (int) $row['spf_pass_count'];

        return new self(
            groupKey: $row['group_key'],
            displayLabel: $row['display_label'],
            totalMessages: $totalMessages,
            dkimPassCount: $dkimPassCount,
            dkimPassRate: $totalMessages > 0 ? round($dkimPassCount / $totalMessages * 100, 1) : 0.0,
            spfPassCount: $spfPassCount,
            spfPassRate: $totalMessages > 0 ? round($spfPassCount / $totalMessages * 100, 1) : 0.0,
            knownSenderId: $row['known_sender_id'],
            senderIsAuthorized: null !== $row['sender_is_authorized']
                ? (bool) (is_string($row['sender_is_authorized']) ? (int) $row['sender_is_authorized'] : $row['sender_is_authorized'])
                : null,
        );
    }
}
