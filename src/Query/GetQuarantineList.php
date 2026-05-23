<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\QuarantineListResult;
use App\Value\Reports\QuarantineReason;
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
    public function forTeam(string $teamId, int $limit = 50, int $offset = 0): array
    {
        /** @var list<array{quarantine_id: string, domain_name: string, reporter_email: string, reason: string, quarantined_at: string, expires_at: string, subject: string, size_bytes: int|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT
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
            WHERE (
                EXISTS (
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
                )
            )
            ORDER BY q.quarantined_at DESC
            LIMIT :limit OFFSET :offset',
            [
                'teamId' => $teamId,
                'unknownDomainReason' => QuarantineReason::UnknownDomain->value,
                'limit' => $limit,
                'offset' => $offset,
            ],
        )->fetchAllAssociative();

        return array_map(QuarantineListResult::fromDatabaseRow(...), $rows);
    }

    public function countForTeam(string $teamId): int
    {
        return (int) $this->database->executeQuery(
            'SELECT COUNT(*)
            FROM quarantined_dmarc_report q
            JOIN received_report_email e ON e.id = q.received_email_id
            WHERE (
                EXISTS (
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
                )
            )',
            [
                'teamId' => $teamId,
                'unknownDomainReason' => QuarantineReason::UnknownDomain->value,
            ],
        )->fetchOne();
    }
}
