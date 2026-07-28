<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainOverviewResult;
use App\Services\DomainHealthClassifier;
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

    /**
     * Newest `dns_check_result` verdict per protocol, as four nullable booleans —
     * the authoritative "is this record healthy right now?" input the health
     * classifier reads.
     *
     * It is joined here rather than derived from `md.*_verified_at` because
     * `CheckDomainDnsHandler` only ever SETS those columns and never clears them:
     * a domain whose SPF record was deleted last month keeps its
     * `spf_verified_at` forever, so the classifier called it fully healthy and it
     * dropped out of "Needs your attention" entirely. NULL here means "no check
     * row for that protocol yet", which the classifier must read as not-checked,
     * never as broken.
     *
     * `DISTINCT ON (type)` picks the newest row per protocol; the outer
     * aggregate-only SELECT always yields exactly one row (all-NULL when the
     * domain has no check rows), which is what makes `ON true` safe. Grouped
     * columns must be listed in every GROUP BY that uses this join.
     */
    private const string LATEST_CHECK_JOIN = '
            LEFT JOIN LATERAL (
                SELECT
                    bool_or(latest.is_valid) FILTER (WHERE latest.type = \'spf\')   AS spf_check_valid,
                    bool_or(latest.is_valid) FILTER (WHERE latest.type = \'dkim\')  AS dkim_check_valid,
                    bool_or(latest.is_valid) FILTER (WHERE latest.type = \'dmarc\') AS dmarc_check_valid,
                    bool_or(latest.is_valid) FILTER (WHERE latest.type = \'mx\')    AS mx_check_valid
                FROM (
                    SELECT DISTINCT ON (dcr.type) dcr.type, dcr.is_valid
                    FROM dns_check_result dcr
                    WHERE dcr.monitored_domain_id = md.id
                    ORDER BY dcr.type, dcr.checked_at DESC
                ) latest
            ) dcv ON true';

    private const string GROUP_BY = 'md.id, md.domain, md.dmarc_verified_at, md.spf_verified_at, md.dkim_verified_at, md.first_report_at, t.id, t.name, dhs.spf_score, dhs.dkim_score, dhs.dmarc_score, dhs.mx_score, dcv.spf_check_valid, dcv.dkim_check_valid, dcv.dmarc_check_valid, dcv.mx_check_valid';

    /**
     * SQL transcription of {@see DomainHealthClassifier::protocolConfigured()},
     * which is `$checkValid ?? $legacyConfigured` — the stored `dns_check_result`
     * verdict wins in BOTH directions when one exists, and only a protocol with no
     * check row at all falls back to the legacy verified-at / snapshot-score
     * derivation. `COALESCE(<nullable bool>, <never-null bool>)` is exactly that,
     * and never yields NULL, which is what keeps the HAVING arms below two-valued.
     *
     * Every column here is grouped (see {@see GROUP_BY}), so the predicate is legal
     * in HAVING — which is where it has to live: the Attention arm is
     * `NOT(all_configured AND pass_rate_ok)`, a disjunction spanning grouped
     * columns AND an aggregate. Splitting the grouped half into WHERE would drop
     * domains that need attention only because of their pass rate.
     */
    private const string ALL_PROTOCOLS_CONFIGURED_EXPR = 'COALESCE(dcv.spf_check_valid, md.spf_verified_at IS NOT NULL)
        AND COALESCE(dcv.dkim_check_valid, md.dkim_verified_at IS NOT NULL)
        AND COALESCE(dcv.dmarc_check_valid, md.dmarc_verified_at IS NOT NULL)
        AND COALESCE(dcv.mx_check_valid, dhs.mx_score IS NOT NULL AND dhs.mx_score >= '.DomainHealthClassifier::MX_CONFIGURED_MIN_SCORE.')';

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

        // Compose conditional WHERE/HAVING fragments per filter. These are a
        // transcription of `DomainHealthClassifier::classifyOverview()`, not an
        // approximation of it — the classifier paints the card badge and feeds the
        // "Need attention" counter, and this filter backs the chip that counter
        // links to. When the two disagree the product contradicts itself: before
        // TASK-098's rule was pushed down here, a domain with a currently-broken
        // SPF record showed the amber badge, was counted in the tally, and then
        // was NOT in the list you got by clicking it. `DomainStatusFilterMatches
        // TheHealthBadgeTest` asserts the parity over every input combination.
        //
        //   - null         → no fragments, returns every domain
        //   - Unverified   → WHERE dmarc_verified_at IS NULL,      no HAVING
        //   - Healthy      → WHERE dmarc_verified_at IS NOT NULL,  HAVING all_protocols_configured AND pass_rate_ok
        //   - Attention    → WHERE dmarc_verified_at IS NOT NULL,  HAVING NOT (all_protocols_configured AND pass_rate_ok)
        //
        // `pass_rate_ok` is `pass_rate >= 90 OR pass_rate IS NULL`. `pass_rate` is
        // genuinely NULL when the domain has no `dmarc_record` rows at all — see
        // {@see buildBaseSelect()} — so a verified, correctly configured, brand-new
        // domain does NOT fall into Attention just because no report has landed
        // yet. That mirrors `awaitingFirstReportVerdict()`. Written as an explicit
        // `IS NULL` arm rather than relying on `NULL < 90` being NULL, so the two
        // arms are exact complements and every domain lands in exactly one chip.
        //
        // Both halves must sit in HAVING even though the protocol predicates are
        // grouped columns: the Attention arm negates a conjunction that spans the
        // grouped columns AND an aggregate, so moving the grouped half into WHERE
        // would wrongly exclude domains that need attention for the other reason.
        //
        // The `dmarc_verified_at IS NOT NULL` guard on both arms exists for the
        // same agreement reason: an unverified domain always classifies as
        // Unverified, so it must appear under neither of the other two chips.
        $passRateOk = '('.self::PASS_RATE_EXPR.' >= '.DomainHealthClassifier::HEALTHY_PASS_RATE_THRESHOLD.' OR '.self::PASS_RATE_EXPR.' IS NULL)';
        $classifiesHealthy = '(('.self::ALL_PROTOCOLS_CONFIGURED_EXPR.') AND '.$passRateOk.')';

        $whereClause = '';
        $havingClause = '';
        if (DomainHealthFilter::Unverified === $statusFilter) {
            $whereClause = ' AND md.dmarc_verified_at IS NULL';
        } elseif (DomainHealthFilter::Healthy === $statusFilter) {
            $whereClause = ' AND md.dmarc_verified_at IS NOT NULL';
            $havingClause = ' HAVING '.$classifiesHealthy;
        } elseif (DomainHealthFilter::Attention === $statusFilter) {
            $whereClause = ' AND md.dmarc_verified_at IS NOT NULL';
            $havingClause = ' HAVING NOT '.$classifiesHealthy;
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

        /** @var list<array{domain_id: string, domain_name: string, total_reports: int|string, latest_report_date: string|null, pass_rate: float|string|null, team_id: string, team_name: string, dmarc_verified_at: string|null, spf_verified_at: string|null, dkim_verified_at: string|null, latest_spf_score: int|string|null, latest_dkim_score: int|string|null, latest_dmarc_score: int|string|null, latest_mx_score: int|string|null, first_report_at: string|null, spf_check_valid: bool|string|null, dkim_check_valid: bool|string|null, dmarc_check_valid: bool|string|null, mx_check_valid: bool|string|null}> $data */
        $data = $this->database->executeQuery(
            $this->buildBaseSelect().'
            WHERE md.team_id IN (:teamIds)'.$whereClause.'
            GROUP BY '.self::GROUP_BY.$havingClause.'
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

        /** @var array{domain_id: string, domain_name: string, total_reports: int|string, latest_report_date: string|null, pass_rate: float|string|null, team_id: string, team_name: string, dmarc_verified_at: string|null, spf_verified_at: string|null, dkim_verified_at: string|null, latest_spf_score: int|string|null, latest_dkim_score: int|string|null, latest_dmarc_score: int|string|null, latest_mx_score: int|string|null, first_report_at: string|null, spf_check_valid: bool|string|null, dkim_check_valid: bool|string|null, dmarc_check_valid: bool|string|null, mx_check_valid: bool|string|null}|false $row */
        $row = $this->database->executeQuery(
            $this->buildBaseSelect().'
            WHERE md.id = :domainId AND md.team_id IN (:teamIds)
            GROUP BY '.self::GROUP_BY,
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
                dhs.mx_score    AS latest_mx_score,
                dcv.spf_check_valid,
                dcv.dkim_check_valid,
                dcv.dmarc_check_valid,
                dcv.mx_check_valid
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
            ) dhs ON true'.self::LATEST_CHECK_JOIN;
    }
}
