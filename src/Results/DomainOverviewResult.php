<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Read model behind every "how is this domain doing?" surface.
 *
 * NO-DATA CONTRACT — `$passRate` is `null`, never `0.0`, when the domain has no
 * DMARC records in the window. `0.0` means "we counted messages and every one
 * of them failed authentication"; `null` means "we have nothing to report on
 * yet". Presenting the two identically was the bug this contract closes.
 *
 * Templates must branch on the null, not on `$totalReports`:
 * {@see DomainOverviewResult::hasPassRateData()} and
 * {@see DomainOverviewResult::isAwaitingFirstReport()} exist so
 * callers never re-derive the rule, and the shared Twig macros in
 * `components/_severity_glyph.html.twig` (`pass_rate_stat`, `pass_rate_class`)
 * turn it into markup.
 */
final readonly class DomainOverviewResult
{
    public function __construct(
        public string $domainId,
        public string $domainName,
        public int $totalReports,
        public ?string $latestReportDate,
        /**
         * 30-day DMARC pass rate as a percentage, or null when no DMARC
         * records exist for the domain in the window. Never conflate with 0.0.
         */
        public ?float $passRate,
        public string $teamId,
        public string $teamName,
        public ?string $dmarcVerifiedAt,
        // TASK-098: per-protocol verification timestamps + latest DNS-snapshot
        // scores joined in from `monitored_domain` + `domain_health_snapshot`.
        // Lets `DomainHealthClassifier` reach the same verdict the per-domain
        // detail page reaches without a second query per domain. All four are
        // nullable because the LATERAL join may return no snapshot row for a
        // brand-new domain whose first DNS check hasn't run yet, and the per-
        // protocol verified-at columns can independently be null.
        public ?string $spfVerifiedAt = null,
        public ?string $dkimVerifiedAt = null,
        public ?int $latestSpfScore = null,
        public ?int $latestDkimScore = null,
        public ?int $latestDmarcScore = null,
        public ?int $latestMxScore = null,
        // Set once a DMARC report has ever been received for the domain. Distinct
        // from `totalReports`, which collapses to 0 after retention purge — this
        // column survives. NextActionResolver uses this (not totalReports) to
        // detect "domain has never received its first report" vs. "received and
        // purged."
        public ?string $firstReportAt = null,
    ) {
    }

    /**
     * @param array{
     *     domain_id: string,
     *     domain_name: string,
     *     total_reports: int|string,
     *     latest_report_date: string|null,
     *     pass_rate: float|string|null,
     *     team_id: string,
     *     team_name: string,
     *     dmarc_verified_at: string|null,
     *     spf_verified_at?: string|null,
     *     dkim_verified_at?: string|null,
     *     latest_spf_score?: int|string|null,
     *     latest_dkim_score?: int|string|null,
     *     latest_dmarc_score?: int|string|null,
     *     latest_mx_score?: int|string|null,
     *     first_report_at?: string|null
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            totalReports: (int) $row['total_reports'],
            latestReportDate: $row['latest_report_date'],
            passRate: null === $row['pass_rate'] ? null : (float) $row['pass_rate'],
            teamId: $row['team_id'],
            teamName: $row['team_name'],
            dmarcVerifiedAt: $row['dmarc_verified_at'],
            spfVerifiedAt: $row['spf_verified_at'] ?? null,
            dkimVerifiedAt: $row['dkim_verified_at'] ?? null,
            latestSpfScore: self::toNullableInt($row['latest_spf_score'] ?? null),
            latestDkimScore: self::toNullableInt($row['latest_dkim_score'] ?? null),
            latestDmarcScore: self::toNullableInt($row['latest_dmarc_score'] ?? null),
            latestMxScore: self::toNullableInt($row['latest_mx_score'] ?? null),
            firstReportAt: $row['first_report_at'] ?? null,
        );
    }

    /**
     * True when there is a real pass rate to render. False means "no DMARC
     * records in the window" — render the awaiting/no-data state, never 0%.
     */
    public function hasPassRateData(): bool
    {
        return null !== $this->passRate;
    }

    /**
     * True when this domain has never received a single DMARC report. Reads
     * `firstReportAt` (an entity column that survives retention purges) rather
     * than `totalReports`, so a domain whose reports were purged reads as
     * "no data in this window", not as "still waiting to be set up".
     */
    public function isAwaitingFirstReport(): bool
    {
        return null === $this->firstReportAt;
    }

    private static function toNullableInt(int|string|null $value): ?int
    {
        return null === $value ? null : (int) $value;
    }
}
