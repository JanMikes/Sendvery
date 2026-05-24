<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\QuarantineListResult;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\QuarantineReasonFilter;
use Doctrine\DBAL\Connection;

/**
 * Reads the quarantined-DMARC-report list scoped to a single team.
 *
 * `quarantined_dmarc_report` has no `team_id` column — quarantine rows are
 * keyed by the claimed domain name, which may not yet belong to any team.
 * Visibility is the union of two rules:
 *
 *   1. Domain ownership — the team owns a `monitored_domain` whose `domain`
 *      (case-insensitively) matches the quarantined row's `domain_name`. This
 *      covers `unverified_domain` and `plan_overage` rows for the team's own
 *      domains, and `unknown_domain` rows after the team has added the matching
 *      domain.
 *
 *   2. Receiving mailbox — for fresh `unknown_domain` rows where the team
 *      hasn't (yet) added the domain, the row is still visible if the
 *      underlying envelope was pulled from one of the team's own
 *      `mailbox_connection`s. Reports landing in the central
 *      `reports@sendvery.com` inbox have a NULL `mailbox_connection_id` and so
 *      stay invisible to every team — they only become visible after a team
 *      claims the domain.
 *
 * The blob column `report_xml_gz` is deliberately excluded — listing only
 * needs metadata and we don't want to pull the compressed XML over the wire.
 */
final readonly class GetQuarantineList
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /** @return list<QuarantineListResult> */
    public function forTeam(
        string $teamId,
        int $limit = 50,
        int $offset = 0,
        ?QuarantineReasonFilter $reasonFilter = null,
        ?string $mailboxFilter = null,
    ): array {
        $sql = 'SELECT
                q.id AS quarantine_id,
                q.domain_name,
                q.reporter_email,
                q.reason,
                q.quarantined_at,
                q.expires_at,
                e.subject,
                e.size_bytes
            FROM quarantined_dmarc_report q
            JOIN received_report_email e ON e.id = q.received_email_id
            WHERE ('.$this->visibilitySql().')';

        $params = [
            'teamId' => $teamId,
            'unknownDomainReason' => QuarantineReason::UnknownDomain->value,
            'limit' => $limit,
            'offset' => $offset,
        ];

        if (null !== $reasonFilter) {
            $sql .= ' AND q.reason = :reasonFilter';
            $params['reasonFilter'] = $reasonFilter->value;
        }

        if (null !== $mailboxFilter) {
            $sql .= ' AND e.mailbox_connection_id = :mailboxFilter';
            $params['mailboxFilter'] = $mailboxFilter;
        }

        $sql .= ' ORDER BY q.quarantined_at DESC LIMIT :limit OFFSET :offset';

        /** @var list<array{quarantine_id: string, domain_name: string, reporter_email: string, reason: string, quarantined_at: string, expires_at: string, subject: string, size_bytes: int|string}> $rows */
        $rows = $this->database->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(QuarantineListResult::fromDatabaseRow(...), $rows);
    }

    public function countForTeam(
        string $teamId,
        ?QuarantineReasonFilter $reasonFilter = null,
        ?string $mailboxFilter = null,
    ): int {
        $sql = 'SELECT COUNT(*)
            FROM quarantined_dmarc_report q
            JOIN received_report_email e ON e.id = q.received_email_id
            WHERE ('.$this->visibilitySql().')';

        $params = [
            'teamId' => $teamId,
            'unknownDomainReason' => QuarantineReason::UnknownDomain->value,
        ];

        if (null !== $reasonFilter) {
            $sql .= ' AND q.reason = :reasonFilter';
            $params['reasonFilter'] = $reasonFilter->value;
        }

        if (null !== $mailboxFilter) {
            $sql .= ' AND e.mailbox_connection_id = :mailboxFilter';
            $params['mailboxFilter'] = $mailboxFilter;
        }

        return (int) $this->database->executeQuery($sql, $params)->fetchOne();
    }

    /**
     * Per-reason counts in one round-trip so the filter chips can show `(N)`
     * labels without an N+1. Missing reasons are filled with `0` so callers
     * can index by every {@see QuarantineReasonFilter} case unconditionally.
     *
     * @return array<value-of<QuarantineReasonFilter>, int>
     */
    public function countByReason(string $teamId, ?string $mailboxFilter = null): array
    {
        $mailboxClause = null === $mailboxFilter ? '' : ' AND e.mailbox_connection_id = :mailboxFilter';

        $parameters = [
            'teamId' => $teamId,
            'unknownDomainReason' => QuarantineReason::UnknownDomain->value,
        ];
        if (null !== $mailboxFilter) {
            $parameters['mailboxFilter'] = $mailboxFilter;
        }

        /** @var list<array{reason: string, total: int|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT q.reason, COUNT(*) AS total
            FROM quarantined_dmarc_report q
            JOIN received_report_email e ON e.id = q.received_email_id
            WHERE ('.$this->visibilitySql().')'.$mailboxClause.'
            GROUP BY q.reason',
            $parameters,
        )->fetchAllAssociative();

        $counts = [];
        foreach (QuarantineReasonFilter::cases() as $filter) {
            $counts[$filter->value] = 0;
        }

        foreach ($rows as $row) {
            // The DB enum is constrained to the three filter cases, but guard
            // against drift — if a new reason ships before the filter enum
            // catches up we simply omit it from the chip counts rather than
            // hard-crash this query.
            if (array_key_exists($row['reason'], $counts)) {
                $counts[$row['reason']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * Shared `WHERE` body for the union-of-rules team-scoping. Extracted so
     * `forTeam`, `countForTeam`, and `countByReason` cannot drift apart.
     */
    private function visibilitySql(): string
    {
        return 'EXISTS (
                    SELECT 1 FROM monitored_domain md
                    WHERE LOWER(md.domain) = LOWER(q.domain_name)
                    AND md.team_id = :teamId
                )
                OR (
                    q.reason = :unknownDomainReason
                    AND EXISTS (
                        SELECT 1 FROM mailbox_connection mc
                        WHERE mc.id = e.mailbox_connection_id
                        AND mc.team_id = :teamId
                    )
                )';
    }
}
