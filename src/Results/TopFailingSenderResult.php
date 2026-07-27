<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\SenderRole;

/**
 * Single-row "top failing sender" tuple for the pass-rate regression banner
 * (TASK-093). Loaded by {@see \App\Query\GetTopFailingSenderForTeam} — the
 * banner only ever needs one sender (the dominant failure cause), so the
 * result shape is intentionally singular rather than a list of N.
 *
 * `senderId` is the {@see \App\Entity\KnownSender} primary key so the banner's
 * "Investigate this sender" link can deep-link directly to that row via the
 * existing `#sender-{id}` anchor on the Sender Inventory page (TASK-038).
 */
final readonly class TopFailingSenderResult
{
    public function __construct(
        public ?string $senderId,
        public string $displayLabel,
        public string $sourceIp,
        public string $domainId,
        public int $failingMessageCount,
        /**
         * The banner's tone depends on this: failures concentrated in a
         * forwarder are the mechanical consequence of forwarding and need no
         * fix, while the same numbers from an unrecognised sender do.
         */
        public ?SenderRole $senderRole = null,
    ) {
    }

    /**
     * `source_ip` is one representative address of the identity, not the whole
     * of it — a gateway with three regional nodes is one row here.
     *
     * @param array{
     *     sender_id: string|null,
     *     display_label: string,
     *     sender_role: string|null,
     *     source_ip: string,
     *     monitored_domain_id: string,
     *     failing_message_count: int|string
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            senderId: $row['sender_id'],
            displayLabel: $row['display_label'],
            sourceIp: $row['source_ip'],
            domainId: $row['monitored_domain_id'],
            failingMessageCount: (int) $row['failing_message_count'],
            senderRole: null !== $row['sender_role'] ? SenderRole::from($row['sender_role']) : null,
        );
    }
}
