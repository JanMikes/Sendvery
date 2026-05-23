<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\QuarantineDetailResult;
use App\Value\Reports\QuarantineReason;
use Doctrine\DBAL\Connection;

/**
 * Reads a single quarantine row with envelope metadata, applying the same
 * union-of-rules team-scoping as {@see GetQuarantineList} (domain ownership OR
 * `unknown_domain` rows received via one of the team's own mailboxes). Returns
 * null when the row doesn't exist or the team can't see it — callers translate
 * that into a 404 so the existence of other tenants' rows isn't leaked.
 */
final readonly class GetQuarantineDetail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forTeam(string $quarantineId, string $teamId): ?QuarantineDetailResult
    {
        /** @var array{quarantine_id: string, domain_name: string, reporter_email: string, reason: string, quarantined_at: string, expires_at: string, subject: string, size_bytes: int|string, envelope_id: string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT
                q.id AS quarantine_id,
                q.domain_name,
                q.reporter_email,
                q.reason,
                q.quarantined_at,
                q.expires_at,
                e.subject,
                e.size_bytes,
                e.id AS envelope_id
            FROM quarantined_dmarc_report q
            JOIN received_report_email e ON e.id = q.received_email_id
            WHERE q.id = :quarantineId
            AND (
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
                'quarantineId' => $quarantineId,
                'teamId' => $teamId,
                'unknownDomainReason' => QuarantineReason::UnknownDomain->value,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return QuarantineDetailResult::fromDatabaseRow($row);
    }
}
