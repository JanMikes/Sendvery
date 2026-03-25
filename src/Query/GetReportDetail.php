<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\ReportDetailResult;
use App\Results\ReportRecordResult;
use Doctrine\DBAL\Connection;

readonly final class GetReportDetail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forReport(string $reportId): ?ReportDetailResult
    {
        $reportRow = $this->database->executeQuery(
            'SELECT
                dr.id AS report_id,
                dr.reporter_org AS reporter_org,
                dr.reporter_email AS reporter_email,
                dr.external_report_id AS external_report_id,
                dr.date_range_begin AS date_range_begin,
                dr.date_range_end AS date_range_end,
                dr.policy_domain AS policy_domain,
                dr.policy_adkim AS policy_adkim,
                dr.policy_aspf AS policy_aspf,
                dr.policy_p AS policy_p,
                dr.policy_sp AS policy_sp,
                dr.policy_pct AS policy_pct,
                dr.processed_at AS processed_at
            FROM dmarc_report dr
            WHERE dr.id = :reportId',
            ['reportId' => $reportId],
        )->fetchAssociative();

        if ($reportRow === false) {
            return null;
        }

        $recordRows = $this->database->executeQuery(
            'SELECT
                rec.id AS record_id,
                rec.source_ip AS source_ip,
                rec.count AS count,
                rec.disposition AS disposition,
                rec.dkim_result AS dkim_result,
                rec.spf_result AS spf_result,
                rec.header_from AS header_from,
                rec.dkim_domain AS dkim_domain,
                rec.dkim_selector AS dkim_selector,
                rec.spf_domain AS spf_domain,
                rec.resolved_hostname AS resolved_hostname,
                rec.resolved_org AS resolved_org
            FROM dmarc_record rec
            WHERE rec.dmarc_report_id = :reportId
            ORDER BY rec.count DESC',
            ['reportId' => $reportId],
        )->fetchAllAssociative();

        $records = array_map(ReportRecordResult::fromDatabaseRow(...), $recordRows);

        return ReportDetailResult::fromDatabaseRow($reportRow, $records);
    }
}
