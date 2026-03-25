<?php

declare(strict_types=1);

namespace App\Results;

final readonly class ReportListResult
{
    public function __construct(
        public string $reportId,
        public string $domainName,
        public string $reporterOrg,
        public string $dateRangeBegin,
        public string $dateRangeEnd,
        public int $recordCount,
        public float $passRate,
    ) {
    }

    /** @param array{report_id: string, domain_name: string, reporter_org: string, date_range_begin: string, date_range_end: string, record_count: int|string, pass_rate: float|string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            reportId: $row['report_id'],
            domainName: $row['domain_name'],
            reporterOrg: $row['reporter_org'],
            dateRangeBegin: $row['date_range_begin'],
            dateRangeEnd: $row['date_range_end'],
            recordCount: (int) $row['record_count'],
            passRate: (float) $row['pass_rate'],
        );
    }
}
