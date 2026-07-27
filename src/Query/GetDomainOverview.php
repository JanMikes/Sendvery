<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainOverviewResult;
use App\Value\DomainHealthFilter;
use App\Value\DomainHealthSort;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetDomainOverview
{
    /**
     * 30-day-window pass rate, deliberately NULL-when-no-data.
     *
     * `NULLIF(SUM(rec.count), 0)` makes the divisor NULL when the domain has no
     * `dmarc_record` rows, so the whole expression evaluates to NULL. There is
     * no `COALESCE(..., 0)` wrapper on purpose: collapsing "we have never seen a
     * message" into a hard `0` made a brand-new domain indistinguishable from
     * one where every single message failed authentication, which is what the
     * `/app/domains` cards were reporting as a red "0.0%".
     *
     * Do NOT reintroduce a zero fallback here. Consumers get `?float` and
     * render an explicit "waiting for first report" state instead.
     */
    private const string PASS_RATE_EXPR = 'SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float / NULLIF(SUM(rec.count), 0) * 100';

    /**
     * Ordering-only variant of {@see PASS_RATE_EXPR}. ORDER BY needs a total
     * order over rows including the no-data ones, and the sort semantics below
     * were designed against a 0-for-no-data value. Never selected — display
     * always uses the nullable expression.
     */
    private const string PASS_RATE_SORT_EXPR = 'COALESCE('.self::PASS_RATE_EXPR.', 0)';

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<DomainOverviewResult>
     */
    public function forTeams(
        array $teamIds,
        ?DomainHealthFilter $statusFilter = null,
        ?DomainHealthSort $sort = null,
    ): array {
        if ([] === $teamIds) {
            return [];
        }

        // Compose conditional WHERE/HAVING fragments per filter:
        //   - null         → no fragments, returns every domain
        //   - Unverified   → WHERE dmarc_verified_at IS NULL,      no HAVING
        //   - Healthy      → WHERE dmarc_verified_at IS NOT NULL,  HAVING pass_rate >= 90 OR pass_rate IS NULL
        //   - Attention    → WHERE dmarc_verified_at IS NOT NULL,  HAVING pass_rate < 90
        //
        // `pass_rate` is genuinely NULL when the domain has no `dmarc_record`
        // rows at all — see {@see buildBaseSelect()}. A verified, brand-new
        // domain therefore does NOT fall into Attention just because no report
        // has landed yet: `NULL < 90` is NULL (not true), so it drops out of
        // Attention, and the explicit `IS NULL` arm keeps it in Healthy. This
        // mirrors `DomainHealthClassifier::classifyOverview()`, which returns
        // Healthy for "correctly configured, awaiting first report" — without
        // the two agreeing, clicking "Need attention" would surface cards
        // rendering a green glyph.
        //
        // The `dmarc_verified_at IS NOT NULL` guard on Healthy exists for the
        // same agreement reason: an unverified domain always classifies as
        // Unverified, so it must never be listed under Healthy — which it
        // would be now that "no reports" no longer disqualifies.
        //
        // NOTE: the Healthy/Attention SQL filters here are still looser than
        // the TASK-098 in-app `DomainHealthClassifier` verdict (which ALSO
        // requires all 4 DNS protocols configured before declaring Healthy).
        // Tightening the SQL filter to match would require pushing the
        // per-protocol score thresholds into SQL and is a v2 refinement.
        $whereClause = '';
        $havingClause = '';
        if (DomainHealthFilter::Unverified === $statusFilter) {
            $whereClause = ' AND md.dmarc_verified_at IS NULL';
        } elseif (DomainHealthFilter::Healthy === $statusFilter) {
            $whereClause = ' AND md.dmarc_verified_at IS NOT NULL';
            $havingClause = ' HAVING '.self::PASS_RATE_EXPR.' >= 90 OR '.self::PASS_RATE_EXPR.' IS NULL';
        } elseif (DomainHealthFilter::Attention === $statusFilter) {
            $whereClause = ' AND md.dmarc_verified_at IS NOT NULL';
            $havingClause = ' HAVING '.self::PASS_RATE_EXPR.' < 90';
        }

        // ORDER BY axis driven by the TASK-040 ?domain_health_sort= param:
        //   - null  (default) → alphabetical domain name (legacy behaviour, kept
        //                       for callers that don't opt into the new axis,
        //                       e.g. the full /app/domains list page).
        //   - Worst → boolean "has any records" sentinel sorts zero-record
        //             domains *after* genuinely failing ones, then pass_rate
        //             ASC, ties broken by report count.
        //   - Best  → pass_rate DESC. Zero-record domains intentionally pinned
        //             to the BOTTOM via NULLIF + NULLS LAST — under a naive
        //             DESC alone they'd float to the top (PostgreSQL NULLS
        //             default to FIRST under DESC). Using NULLIF/NULLS LAST
        //             keeps the semantics explicit even if a future refactor
        //             drops the COALESCE around $passRateExpr.
        //   - Most  → total_reports DESC then pass_rate ASC (ties → surface
        //             the worst high-volume domain first).
        $orderClause = match ($sort) {
            DomainHealthSort::Worst => 'NULLIF(SUM(rec.count), 0) IS NULL, '.self::PASS_RATE_SORT_EXPR.' ASC, COUNT(dr.id) DESC',
            DomainHealthSort::Best => self::PASS_RATE_EXPR.' DESC NULLS LAST, COUNT(dr.id) DESC',
            DomainHealthSort::Most => 'COUNT(dr.id) DESC, '.self::PASS_RATE_SORT_EXPR.' ASC',
            null => 'md.domain ASC',
        };

        /** @var list<array{domain_id: string, domain_name: string, total_reports: int|string, latest_report_date: string|null, pass_rate: float|string|null, team_id: string, team_name: string, dmarc_verified_at: string|null, spf_verified_at: string|null, dkim_verified_at: string|null, latest_spf_score: int|string|null, latest_dkim_score: int|string|null, latest_dmarc_score: int|string|null, latest_mx_score: int|string|null, first_report_at: string|null}> $data */
        $data = $this->database->executeQuery(
            $this->buildBaseSelect().'
            WHERE md.team_id IN (:teamIds)'.$whereClause.'
            GROUP BY md.id, md.domain, md.dmarc_verified_at, md.spf_verified_at, md.dkim_verified_at, md.first_report_at, t.id, t.name, dhs.spf_score, dhs.dkim_score, dhs.dmarc_score, dhs.mx_score'.$havingClause.'
            ORDER BY '.$orderClause,
            [
                'teamIds' => $teamIds,
                'pass' => 'pass',
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(DomainOverviewResult::fromDatabaseRow(...), $data);
    }

    /**
     * Single-domain variant of {@see forTeams()} that returns the same DTO
     * shape (severity inputs included) for one domain — needed by the per-
     * domain detail page so the same `DomainHealthClassifier` that drives the
     * list-card glyph also drives the detail-page banner severity, without
     * needing a second query against `monitored_domain`.
     *
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function forDomain(string $domainId, array $teamIds): ?DomainOverviewResult
    {
        if ([] === $teamIds) {
            return null;
        }

        /** @var array{domain_id: string, domain_name: string, total_reports: int|string, latest_report_date: string|null, pass_rate: float|string|null, team_id: string, team_name: string, dmarc_verified_at: string|null, spf_verified_at: string|null, dkim_verified_at: string|null, latest_spf_score: int|string|null, latest_dkim_score: int|string|null, latest_dmarc_score: int|string|null, latest_mx_score: int|string|null, first_report_at: string|null}|false $row */
        $row = $this->database->executeQuery(
            $this->buildBaseSelect().'
            WHERE md.id = :domainId AND md.team_id IN (:teamIds)
            GROUP BY md.id, md.domain, md.dmarc_verified_at, md.spf_verified_at, md.dkim_verified_at, md.first_report_at, t.id, t.name, dhs.spf_score, dhs.dkim_score, dhs.dmarc_score, dhs.mx_score',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
                'pass' => 'pass',
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return DomainOverviewResult::fromDatabaseRow($row);
    }

    /**
     * Unfiltered domain count for the team scope — used by the domains list
     * empty-state branch to distinguish "no domains at all" from "no domains
     * match the current filter".
     *
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function countForTeams(array $teamIds): int
    {
        if ([] === $teamIds) {
            return 0;
        }

        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM monitored_domain WHERE team_id IN (:teamIds)',
            [
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchOne();
    }

    /**
     * Count of domains that have not yet passed DMARC verification —
     * dmarc_verified_at IS NULL. Matches DomainHealthFilter::Unverified semantics.
     * Used by NavCountsExtension to drive the sidebar badge.
     *
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function countUnverifiedForTeams(array $teamIds): int
    {
        if ([] === $teamIds) {
            return 0;
        }

        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM monitored_domain WHERE team_id IN (:teamIds) AND dmarc_verified_at IS NULL',
            [
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchOne();
    }

    /**
     * Shared SELECT + LATERAL JOIN body used by both {@see forTeams()} and
     * {@see forDomain()}. Keeping the projection identical means the two
     * callers always feed the classifier the same shape.
     *
     * The `LEFT JOIN LATERAL` mirrors {@see GetDnsHealthOverview::forTeams()} —
     * one extra index-backed lookup per domain (covered by
     * `idx_health_snapshot_domain_date`), bounded at LIMIT 1.
     */
    private function buildBaseSelect(): string
    {
        return 'SELECT
                md.id AS domain_id,
                md.domain AS domain_name,
                md.dmarc_verified_at AS dmarc_verified_at,
                md.spf_verified_at AS spf_verified_at,
                md.dkim_verified_at AS dkim_verified_at,
                md.first_report_at AS first_report_at,
                t.id::text AS team_id,
                t.name AS team_name,
                COUNT(dr.id) AS total_reports,
                MAX(dr.date_range_end) AS latest_report_date,
                '.self::PASS_RATE_EXPR.' AS pass_rate,
                dhs.spf_score   AS latest_spf_score,
                dhs.dkim_score  AS latest_dkim_score,
                dhs.dmarc_score AS latest_dmarc_score,
                dhs.mx_score    AS latest_mx_score
            FROM monitored_domain md
            JOIN team t ON t.id = md.team_id
            LEFT JOIN dmarc_report dr ON dr.monitored_domain_id = md.id
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            LEFT JOIN LATERAL (
                SELECT spf_score, dkim_score, dmarc_score, mx_score
                FROM domain_health_snapshot
                WHERE monitored_domain_id = md.id
                ORDER BY checked_at DESC
                LIMIT 1
            ) dhs ON true';
    }
}
