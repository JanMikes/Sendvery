<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\MailboxActivitySummary;
use App\Results\MailboxDetailResult;
use App\Results\MailboxRecentEnvelope;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads aggregate counts and a recent-envelopes list for the per-mailbox
 * detail page. Cross-tenant guarded — `forMailbox()` returns null when the
 * mailbox ID either doesn't exist or belongs to a team the caller isn't a
 * member of. The 30/7-day aggregates and the recent-envelopes list run in
 * separate queries (clearer SQL than nested CTEs) and only the first one
 * carries the team-scope check; the rest re-use the same mailbox UUID.
 *
 * `summaryForTeam()` is the batch sibling for the list page — one row per
 * mailbox so the inline-activity column doesn't N+1.
 */
final readonly class GetMailboxDetail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function forMailbox(string $mailboxId, array $teamIds): ?MailboxDetailResult
    {
        if ([] === $teamIds) {
            return null;
        }

        /** @var array{mailbox_id: string, team_id: string, host: string, port: int|string, type: string, encryption: string, is_active: bool|string|int, last_polled_at: string|null, last_error: string|null, envelopes_total: int|string, envelopes_30d: int|string, envelopes_7d: int|string, reports_parsed: int|string, envelopes_quarantined: int|string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                mc.id AS mailbox_id,
                mc.team_id,
                mc.host,
                mc.port,
                mc.type,
                mc.encryption,
                mc.is_active,
                mc.last_polled_at,
                mc.last_error,
                COALESCE((
                    SELECT COUNT(*) FROM received_report_email e
                    WHERE e.mailbox_connection_id = mc.id
                ), 0) AS envelopes_total,
                COALESCE((
                    SELECT COUNT(*) FROM received_report_email e
                    WHERE e.mailbox_connection_id = mc.id
                    AND e.received_at >= NOW() - INTERVAL \'30 days\'
                ), 0) AS envelopes_30d,
                COALESCE((
                    SELECT COUNT(*) FROM received_report_email e
                    WHERE e.mailbox_connection_id = mc.id
                    AND e.received_at >= NOW() - INTERVAL \'7 days\'
                ), 0) AS envelopes_7d,
                COALESCE((
                    SELECT COUNT(*) FROM dmarc_report dr
                    JOIN received_report_email e ON e.id = dr.source_envelope_id
                    WHERE e.mailbox_connection_id = mc.id
                ), 0) AS reports_parsed,
                COALESCE((
                    SELECT COUNT(*) FROM quarantined_dmarc_report q
                    JOIN received_report_email e ON e.id = q.received_email_id
                    WHERE e.mailbox_connection_id = mc.id
                ), 0) AS envelopes_quarantined
            FROM mailbox_connection mc
            WHERE mc.id = :mailboxId
              AND mc.team_id IN (:teamIds)',
            [
                'mailboxId' => $mailboxId,
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return MailboxDetailResult::fromDatabaseRow($row);
    }

    /**
     * Recent envelopes for a mailbox, newest first. Caller MUST have already
     * gone through {@see forMailbox()} for the team-scope check — this method
     * trusts the mailbox UUID is one the user is allowed to see.
     *
     * @return list<MailboxRecentEnvelope>
     */
    public function recentEnvelopesForMailbox(string $mailboxId, int $limit = 20): array
    {
        /** @var list<array{envelope_id: string, received_at: string, from_address: string, subject: string, report_id: string|null, quarantine_id: string|null}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT
                e.id AS envelope_id,
                e.received_at,
                e.from_address,
                e.subject,
                dr.id AS report_id,
                q.id AS quarantine_id
            FROM received_report_email e
            LEFT JOIN dmarc_report dr ON dr.source_envelope_id = e.id
            LEFT JOIN quarantined_dmarc_report q ON q.received_email_id = e.id
            WHERE e.mailbox_connection_id = :mailboxId
            ORDER BY e.received_at DESC
            LIMIT :limit',
            [
                'mailboxId' => $mailboxId,
                'limit' => $limit,
            ],
        )->fetchAllAssociative();

        return array_map(MailboxRecentEnvelope::fromDatabaseRow(...), $rows);
    }

    /**
     * Batched 30-day activity summary for every mailbox a team owns. Used by
     * the list page so the inline "12 envelopes / 11 reports / 1 quarantined"
     * cell doesn't run three subqueries per row. The map is keyed by mailbox
     * UUID; missing keys (a mailbox with zero activity) fall back to the
     * caller's chosen default (typically {@see MailboxActivitySummary::empty()}).
     *
     * @param list<string> $mailboxIds mailbox UUIDs to load activity for
     *
     * @return array<string, MailboxActivitySummary>
     */
    public function summaryForMailboxes(array $mailboxIds): array
    {
        if ([] === $mailboxIds) {
            return [];
        }

        /** @var list<array{mailbox_id: string, envelopes_30d: int|string, reports_30d: int|string, quarantined_30d: int|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT
                e.mailbox_connection_id AS mailbox_id,
                COUNT(*) FILTER (WHERE e.received_at >= NOW() - INTERVAL \'30 days\') AS envelopes_30d,
                COUNT(dr.id) FILTER (WHERE e.received_at >= NOW() - INTERVAL \'30 days\') AS reports_30d,
                COUNT(q.id) FILTER (WHERE e.received_at >= NOW() - INTERVAL \'30 days\') AS quarantined_30d
            FROM received_report_email e
            LEFT JOIN dmarc_report dr ON dr.source_envelope_id = e.id
            LEFT JOIN quarantined_dmarc_report q ON q.received_email_id = e.id
            WHERE e.mailbox_connection_id IN (:mailboxIds)
            GROUP BY e.mailbox_connection_id',
            [
                'mailboxIds' => $mailboxIds,
            ],
            [
                'mailboxIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $summary = [];
        foreach ($rows as $row) {
            $summary[$row['mailbox_id']] = MailboxActivitySummary::fromDatabaseRow($row);
        }

        return $summary;
    }
}
