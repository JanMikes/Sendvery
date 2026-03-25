<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\PassRateTrendResult;
use Doctrine\DBAL\Connection;

final readonly class GetDomainPassRateTrend
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /** @return array<PassRateTrendResult> */
    public function forDomain(string $domainId, int $days = 90): array
    {
        /** @var list<array{date: string, pass_count: int|string, fail_count: int|string}> $data */
        $data = $this->database->executeQuery(
            'SELECT
                d::date AS date,
                COALESCE(SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END), 0) AS pass_count,
                COALESCE(SUM(CASE WHEN rec.dkim_result != :pass AND rec.spf_result != :pass THEN rec.count ELSE 0 END), 0) AS fail_count
            FROM generate_series(
                (NOW() - make_interval(days => :days))::date,
                NOW()::date,
                \'1 day\'::interval
            ) AS d
            LEFT JOIN dmarc_report dr
                ON dr.monitored_domain_id = :domainId
                AND dr.date_range_begin::date <= d::date
                AND dr.date_range_end::date >= d::date
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            GROUP BY d::date
            ORDER BY d::date ASC',
            [
                'domainId' => $domainId,
                'pass' => 'pass',
                'days' => $days,
            ],
        )->fetchAllAssociative();

        return array_map(PassRateTrendResult::fromDatabaseRow(...), $data);
    }

    /** @return array<PassRateTrendResult> */
    public function forTeam(string $teamId, int $days = 30): array
    {
        /** @var list<array{date: string, pass_count: int|string, fail_count: int|string}> $data */
        $data = $this->database->executeQuery(
            'SELECT
                d::date AS date,
                COALESCE(SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END), 0) AS pass_count,
                COALESCE(SUM(CASE WHEN rec.dkim_result != :pass AND rec.spf_result != :pass THEN rec.count ELSE 0 END), 0) AS fail_count
            FROM generate_series(
                (NOW() - make_interval(days => :days))::date,
                NOW()::date,
                \'1 day\'::interval
            ) AS d
            LEFT JOIN dmarc_report dr
                ON dr.date_range_begin::date <= d::date
                AND dr.date_range_end::date >= d::date
            LEFT JOIN monitored_domain md
                ON md.id = dr.monitored_domain_id
                AND md.team_id = :teamId
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            GROUP BY d::date
            ORDER BY d::date ASC',
            [
                'teamId' => $teamId,
                'pass' => 'pass',
                'days' => $days,
            ],
        )->fetchAllAssociative();

        return array_map(PassRateTrendResult::fromDatabaseRow(...), $data);
    }
}
