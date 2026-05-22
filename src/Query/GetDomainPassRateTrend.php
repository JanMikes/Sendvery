<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\PassRateTrendResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetDomainPassRateTrend
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<PassRateTrendResult>
     */
    public function forDomain(string $domainId, array $teamIds, int $days = 90): array
    {
        if ([] === $teamIds) {
            return [];
        }

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
            WHERE EXISTS (
                SELECT 1 FROM monitored_domain md
                WHERE md.id = :domainId AND md.team_id IN (:teamIds)
            )
            GROUP BY d::date
            ORDER BY d::date ASC',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
                'pass' => 'pass',
                'days' => $days,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(PassRateTrendResult::fromDatabaseRow(...), $data);
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<PassRateTrendResult>
     */
    public function forTeams(array $teamIds, int $days = 30): array
    {
        if ([] === $teamIds) {
            return [];
        }

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
                AND md.team_id IN (:teamIds)
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            GROUP BY d::date
            ORDER BY d::date ASC',
            [
                'teamIds' => $teamIds,
                'pass' => 'pass',
                'days' => $days,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(PassRateTrendResult::fromDatabaseRow(...), $data);
    }
}
