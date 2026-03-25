<?php

declare(strict_types=1);

namespace App\Results;

readonly final class DomainOverviewResult
{
    public function __construct(
        public string $domainId,
        public string $domainName,
        public int $totalReports,
        public ?string $latestReportDate,
        public float $passRate,
    ) {
    }

    /** @param array{domain_id: string, domain_name: string, total_reports: int, latest_report_date: ?string, pass_rate: float} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            totalReports: (int) $row['total_reports'],
            latestReportDate: $row['latest_report_date'],
            passRate: (float) $row['pass_rate'],
        );
    }
}
