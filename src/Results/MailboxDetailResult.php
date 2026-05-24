<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Snapshot of a single mailbox connection for the detail page: who it points
 * at (host/port/encryption), when it last polled, what (if anything) went
 * wrong, and a stat-row aggregate over the envelopes it has pulled in.
 *
 * Stat counts are SQL-side aggregates — the controller surfaces them as
 * clickable cells into the filtered reports / quarantine list, so they MUST
 * stay accurate under pagination on those pages.
 */
final readonly class MailboxDetailResult
{
    public function __construct(
        public string $mailboxId,
        public string $teamId,
        public string $host,
        public int $port,
        public string $type,
        public string $encryption,
        public bool $isActive,
        public ?string $lastPolledAt,
        public ?string $lastError,
        public int $envelopesTotal,
        public int $envelopes30d,
        public int $envelopes7d,
        public int $reportsParsed,
        public int $envelopesQuarantined,
    ) {
    }

    /**
     * @param array{
     *     mailbox_id: string,
     *     team_id: string,
     *     host: string,
     *     port: int|string,
     *     type: string,
     *     encryption: string,
     *     is_active: bool|string|int,
     *     last_polled_at: string|null,
     *     last_error: string|null,
     *     envelopes_total: int|string,
     *     envelopes_30d: int|string,
     *     envelopes_7d: int|string,
     *     reports_parsed: int|string,
     *     envelopes_quarantined: int|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            mailboxId: $row['mailbox_id'],
            teamId: $row['team_id'],
            host: $row['host'],
            port: (int) $row['port'],
            type: $row['type'],
            encryption: $row['encryption'],
            isActive: (bool) $row['is_active'],
            lastPolledAt: $row['last_polled_at'],
            lastError: $row['last_error'],
            envelopesTotal: (int) $row['envelopes_total'],
            envelopes30d: (int) $row['envelopes_30d'],
            envelopes7d: (int) $row['envelopes_7d'],
            reportsParsed: (int) $row['reports_parsed'],
            envelopesQuarantined: (int) $row['envelopes_quarantined'],
        );
    }
}
