<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

use App\Query\GetDomainPassRateTrend;
use App\Query\GetReportDetail;
use App\Query\GetReportSenderGroups;
use App\Repository\DmarcReportRepository;
use Ramsey\Uuid\UuidInterface;

/**
 * Loads a team-scoped report via the existing read queries and hands the data to
 * {@see ReportFactsBuilder}. Thin I/O wrapper: returns null when the report isn't
 * visible to the team (so the caller answers "not found" with no API call), and
 * otherwise returns the fully pre-computed fact pack.
 */
final readonly class ReportInsightAnalyzer
{
    public function __construct(
        private GetReportDetail $getReportDetail,
        private GetReportSenderGroups $getReportSenderGroups,
        private GetDomainPassRateTrend $passRateTrend,
        private DmarcReportRepository $reportRepository,
        private ReportFactsBuilder $factsBuilder,
    ) {
    }

    public function analyzeReport(UuidInterface $reportId, UuidInterface $teamId): ?ReportInsightFacts
    {
        $teamIds = [$teamId->toString()];

        $detail = $this->getReportDetail->forReport($reportId->toString(), $teamIds);
        if (null === $detail) {
            return null;
        }

        // GetReportDetail already enforced team visibility above; load the entity
        // (system-scoped) only for its monitored-domain id, which the trend query
        // needs and the read DTO omits.
        $report = $this->reportRepository->get($reportId);

        $senderGroups = $this->getReportSenderGroups->forReport($reportId->toString(), $teamIds);
        // array_values: the builder walks the trend by integer index, so it needs a true list.
        $trend = array_values($this->passRateTrend->forDomain($report->monitoredDomain->id->toString(), $teamIds));

        return $this->factsBuilder->build($detail, $senderGroups, $trend);
    }
}
