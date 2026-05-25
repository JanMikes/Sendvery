<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Per-sender 30-day activity tuple used by the
 * {@see \App\Services\SenderAuthorizationAdvisor} to decide whether a
 * "you should authorize / revoke this sender" recommendation is warranted
 * (TASK-092). Loaded in one batched query keyed by source IP — mirrors the
 * {@see MailboxActivitySummary} batched-load pattern so the Sender Inventory
 * page doesn't N+1 across hundreds of senders.
 *
 * Pass rate here is DKIM-only on purpose: the advisor uses it to decide
 * "looks legitimate" vs "looks like spoofing", and DKIM is the alignment
 * signal a forwarded mail keeps intact. SPF-by-itself would mark
 * legitimately-forwarded marketing mail as failing.
 */
final readonly class SenderActivity30Day
{
    public function __construct(
        public int $totalMessages,
        public float $dkimPassRate,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0.0);
    }

    /** @param array{total_messages_30d: int|string, dkim_pass_count_30d: int|string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        $total = (int) $row['total_messages_30d'];
        $dkimPassCount = (int) $row['dkim_pass_count_30d'];

        return new self(
            totalMessages: $total,
            dkimPassRate: $total > 0 ? round($dkimPassCount / $total * 100, 1) : 0.0,
        );
    }
}
