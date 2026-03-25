<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DashboardStatsResult;
use Doctrine\DBAL\Connection;

final readonly class GetDashboardStats
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forTeam(string $teamId): DashboardStatsResult
    {
        $row = $this->database->executeQuery(
            'SELECT
                (SELECT COUNT(*) FROM monitored_domain WHERE team_id = :teamId) AS total_domains,
                COALESCE((
                    SELECT COUNT(*)
                    FROM dmarc_report dr
                    JOIN monitored_domain md ON md.id = dr.monitored_domain_id
                    WHERE md.team_id = :teamId
                    AND dr.date_range_end >= NOW() - INTERVAL \'30 days\'
                ), 0) AS total_reports_30d,
                COALESCE((
                    SELECT SUM(rec.count)
                    FROM dmarc_record rec
                    JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
                    JOIN monitored_domain md ON md.id = dr.monitored_domain_id
                    WHERE md.team_id = :teamId
                    AND dr.date_range_end >= NOW() - INTERVAL \'30 days\'
                ), 0) AS total_messages,
                COALESCE((
                    SELECT
                        SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                        / NULLIF(SUM(rec.count), 0)
                        * 100
                    FROM dmarc_record rec
                    JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
                    JOIN monitored_domain md ON md.id = dr.monitored_domain_id
                    WHERE md.team_id = :teamId
                    AND dr.date_range_end >= NOW() - INTERVAL \'30 days\'
                ), 0) AS pass_rate',
            [
                'teamId' => $teamId,
                'pass' => 'pass',
            ],
        )->fetchAssociative();

        return new DashboardStatsResult(
            totalDomains: (int) $row['total_domains'],
            totalReportsLast30Days: (int) $row['total_reports_30d'],
            overallPassRate: (float) $row['pass_rate'],
            totalMessages: (int) $row['total_messages'],
        );
    }
}
