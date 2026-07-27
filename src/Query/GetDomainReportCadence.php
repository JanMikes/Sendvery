<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainReportCadenceResult;
use Doctrine\DBAL\Connection;

/**
 * How often each domain actually receives DMARC reports, and when the last one
 * landed.
 *
 * Cadence is measured, not assumed. Most reporters send one aggregate report
 * per day, but plenty send every few hours and some weekly, and a domain
 * monitored by three reporters has a much tighter arrival rhythm than one
 * monitored by a single reporter. Comparing every domain against one global
 * "should have heard by now" number would either cry wolf at the weekly
 * reporters or stay silent for days on the busy ones.
 *
 * `processed_at` rather than `date_range_end` on purpose: the question is when
 * a report ARRIVED at Sendvery, not what period the reporter was describing. A
 * backfilled report covering last week is evidence the pipeline is alive today.
 */
final readonly class GetDomainReportCadence
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Every domain that has ever received a report. Domains that have never
     * received one are deliberately absent: they have no cadence to fall
     * silent against, and "never started" is a setup problem that the
     * verification path already owns.
     *
     * @return list<DomainReportCadenceResult>
     */
    public function forAllDomains(): array
    {
        /** @var list<array{domain_id: string, domain_name: string, team_id: string, last_report_at: string, report_count: int|string, median_gap_seconds: float|string|null}> $rows */
        $rows = $this->database->executeQuery(
            // LAG produces NULL for each domain's first report; PERCENTILE_CONT
            // skips NULLs, so a single-report domain yields a NULL median rather
            // than a fabricated zero-second cadence.
            'WITH arrivals AS (
                SELECT
                    dr.monitored_domain_id AS domain_id,
                    dr.processed_at,
                    EXTRACT(EPOCH FROM (
                        dr.processed_at - LAG(dr.processed_at) OVER (
                            PARTITION BY dr.monitored_domain_id
                            ORDER BY dr.processed_at
                        )
                    )) AS gap_seconds
                FROM dmarc_report dr
            )
            SELECT
                md.id::text AS domain_id,
                md.domain AS domain_name,
                md.team_id::text AS team_id,
                MAX(a.processed_at) AS last_report_at,
                COUNT(a.processed_at) AS report_count,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY a.gap_seconds) AS median_gap_seconds
            FROM monitored_domain md
            JOIN arrivals a ON a.domain_id = md.id
            GROUP BY md.id, md.domain, md.team_id
            ORDER BY MAX(a.processed_at) ASC',
        )->fetchAllAssociative();

        return array_map(DomainReportCadenceResult::fromDatabaseRow(...), $rows);
    }
}
