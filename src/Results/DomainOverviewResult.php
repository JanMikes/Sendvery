<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\DomainHealthFilter;

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
    ) {
    }

    /** @param array{domain_id: string, domain_name: string, total_reports: int|string, latest_report_date: string|null, pass_rate: float|string, team_id: string, team_name: string, dmarc_verified_at: string|null} $row */
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
        );
    }

    public function severity(): DomainHealthFilter
    {
        return DomainHealthFilter::fromOverview($this);
    }
}
