<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DomainOverviewResult
{
    public function __construct(
        public string $domainId,
        public string $domainName,
        public int $totalReports,
        public ?string $latestReportDate,
        public float $passRate,
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
    ) {
    }

    /**
     * @param array{
     *     domain_id: string,
     *     domain_name: string,
     *     total_reports: int|string,
     *     latest_report_date: string|null,
     *     pass_rate: float|string,
     *     team_id: string,
     *     team_name: string,
     *     dmarc_verified_at: string|null,
     *     spf_verified_at?: string|null,
     *     dkim_verified_at?: string|null,
     *     latest_spf_score?: int|string|null,
     *     latest_dkim_score?: int|string|null,
     *     latest_dmarc_score?: int|string|null,
     *     latest_mx_score?: int|string|null
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            totalReports: (int) $row['total_reports'],
            latestReportDate: $row['latest_report_date'],
            passRate: (float) $row['pass_rate'],
            teamId: $row['team_id'],
            teamName: $row['team_name'],
            dmarcVerifiedAt: $row['dmarc_verified_at'],
            spfVerifiedAt: $row['spf_verified_at'] ?? null,
            dkimVerifiedAt: $row['dkim_verified_at'] ?? null,
            latestSpfScore: self::toNullableInt($row['latest_spf_score'] ?? null),
            latestDkimScore: self::toNullableInt($row['latest_dkim_score'] ?? null),
            latestDmarcScore: self::toNullableInt($row['latest_dmarc_score'] ?? null),
            latestMxScore: self::toNullableInt($row['latest_mx_score'] ?? null),
        );
    }

    private static function toNullableInt(int|string|null $value): ?int
    {
        return null === $value ? null : (int) $value;
    }
}
