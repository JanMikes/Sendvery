<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Compact 30-day activity tuple for the inline summary row on the mailboxes
 * list page. Loaded in one batch query (one row per mailbox) so the list
 * doesn't N+1 across the team's mailboxes.
 */
final readonly class MailboxActivitySummary
{
    public function __construct(
        public int $envelopes30d,
        public int $reports30d,
        public int $quarantined30d,
    ) {
    }

    /**
     * @param array{envelopes_30d: int|string, reports_30d: int|string, quarantined_30d: int|string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            envelopes30d: (int) $row['envelopes_30d'],
            reports30d: (int) $row['reports_30d'],
            quarantined30d: (int) $row['quarantined_30d'],
        );
    }

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }
}
