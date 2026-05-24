<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainIngestionMatrixResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Per-domain ingestion classification for `/app/mailboxes`. Inspects the 5
 * most-recent parsed DMARC reports per domain and reports which source(s)
 * (central inbox via DNS, or BYO mailbox) backed those envelopes.
 *
 * Window choice: "last 5 reports" rather than "last 30 days" so low-volume
 * domains aren't falsely classified as `none` just because no report arrived
 * in the recent window. The classification cares about the *current* path the
 * domain is using, not its 30-day cadence.
 *
 * Join path: `monitored_domain → dmarc_report (source_envelope_id) →
 * received_report_email`. The FK is `ON DELETE SET NULL`, so reports whose
 * envelopes have been purged still appear but contribute NULL source — they
 * fall through `COUNT(source)` and don't affect the classification.
 *
 * Misconfiguration detection: `mixed` only when both `central_inbox` AND
 * `byo_mailbox` appear in the same 5-report sample. The UI flags this so the
 * user can stop the duplicate ingestion.
 */
readonly class GetDomainIngestionMatrix
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return list<DomainIngestionMatrixResult>
     */
    public function forTeams(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        // The `eligible_domain_ids` CTE narrows the report scan to the
        // caller's tenants BEFORE the ROW_NUMBER() window in `ranked_reports`
        // runs. Postgres CTEs are optimisation fences by default (CTE
        // Materialization), so without this gate Postgres would scan the
        // entire `dmarc_report` table on every page load and only filter to
        // the tenant set after the window function had already materialised
        // ranks for every row.
        //
        // We also reuse `eligible_domain_ids` for the outer
        // `WHERE md.id IN (...)` gate so domains with zero reports still
        // surface (they're in the CTE even if they have no row in
        // `per_domain`). Single source of truth for "this tenant's domains".
        $sql = <<<'SQL'
            WITH eligible_domain_ids AS (
                SELECT id
                FROM monitored_domain
                WHERE team_id IN (:teamIds)
            ),
            ranked_reports AS (
                SELECT
                    dr.monitored_domain_id,
                    dr.processed_at,
                    e.source AS envelope_source,
                    e.mailbox_connection_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY dr.monitored_domain_id
                        ORDER BY dr.processed_at DESC
                    ) AS rn
                FROM dmarc_report dr
                LEFT JOIN received_report_email e ON e.id = dr.source_envelope_id
                WHERE dr.monitored_domain_id IN (SELECT id FROM eligible_domain_ids)
            ),
            sampled AS (
                SELECT * FROM ranked_reports WHERE rn <= 5
            ),
            per_domain AS (
                SELECT
                    monitored_domain_id,
                    BOOL_OR(envelope_source = 'central_inbox') AS has_dns,
                    BOOL_OR(envelope_source = 'byo_mailbox') AS has_mailbox,
                    COUNT(envelope_source) AS sample_count,
                    MAX(processed_at) AS last_report_at,
                    (
                        SELECT mailbox_connection_id
                        FROM sampled s2
                        WHERE s2.monitored_domain_id = sampled.monitored_domain_id
                          AND s2.envelope_source = 'byo_mailbox'
                          AND s2.mailbox_connection_id IS NOT NULL
                        ORDER BY s2.processed_at DESC
                        LIMIT 1
                    ) AS recent_mailbox_id
                FROM sampled
                GROUP BY monitored_domain_id
            )
            SELECT
                md.id   AS domain_id,
                md.domain AS domain_name,
                CASE
                    WHEN pd.sample_count IS NULL OR pd.sample_count = 0 THEN 'none'
                    WHEN pd.has_dns AND pd.has_mailbox THEN 'mixed'
                    WHEN pd.has_dns THEN 'dns'
                    WHEN pd.has_mailbox THEN 'mailbox'
                    ELSE 'none'
                END AS path,
                pd.last_report_at,
                mc.id   AS mailbox_id,
                mc.host AS mailbox_host,
                mc.port AS mailbox_port
            FROM monitored_domain md
            LEFT JOIN per_domain pd ON pd.monitored_domain_id = md.id
            LEFT JOIN mailbox_connection mc ON mc.id = pd.recent_mailbox_id
            WHERE md.id IN (SELECT id FROM eligible_domain_ids)
            ORDER BY md.domain ASC
            SQL;

        /** @var list<array{domain_id: string, domain_name: string, path: string, last_report_at: string|null, mailbox_id: string|null, mailbox_host: string|null, mailbox_port: int|string|null}> $rows */
        $rows = $this->database->executeQuery(
            $sql,
            ['teamIds' => $teamIds],
            ['teamIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(DomainIngestionMatrixResult::fromDatabaseRow(...), $rows);
    }
}
