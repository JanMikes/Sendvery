<?php

declare(strict_types=1);

namespace App\Results;

final readonly class ReportDetailResult
{
    /**
     * @param array<ReportRecordResult> $records
     */
    public function __construct(
        public string $reportId,
        public string $reporterOrg,
        public string $reporterEmail,
        public string $externalReportId,
        public string $dateRangeBegin,
        public string $dateRangeEnd,
        public string $policyDomain,
        public string $policyAdkim,
        public string $policyAspf,
        public string $policyP,
        public ?string $policySp,
        public int $policyPct,
        public string $processedAt,
        public array $records,
    ) {
    }

    /**
     * @param array{report_id: string, reporter_org: string, reporter_email: string, external_report_id: string, date_range_begin: string, date_range_end: string, policy_domain: string, policy_adkim: string, policy_aspf: string, policy_p: string, policy_sp: string|null, policy_pct: int|string, processed_at: string} $row
     * @param array<ReportRecordResult>                                                                                                                                                                                                                                                                                      $records
     */
    public static function fromDatabaseRow(array $row, array $records): self
    {
        return new self(
            reportId: $row['report_id'],
            reporterOrg: $row['reporter_org'],
            reporterEmail: $row['reporter_email'],
            externalReportId: $row['external_report_id'],
            dateRangeBegin: $row['date_range_begin'],
            dateRangeEnd: $row['date_range_end'],
            policyDomain: $row['policy_domain'],
            policyAdkim: $row['policy_adkim'],
            policyAspf: $row['policy_aspf'],
            policyP: $row['policy_p'],
            policySp: $row['policy_sp'],
            policyPct: (int) $row['policy_pct'],
            processedAt: $row['processed_at'],
            records: $records,
        );
    }
}
