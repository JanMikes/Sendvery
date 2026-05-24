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
     * Per-domain pass-rate trend buckets for the TASK-040 sparkline on the
     * `/app` Domain Health card. Returns one float (pass-rate 0-100) per
     * 3-day bucket within the trailing `$days` window — for the default
     * 30-day window that yields 10 points per domain, which fits cleanly
     * into a tiny ~80×20 inline SVG without crowding.
     *
     * Empty buckets (no messages observed) collapse to 0.0 rather than NULL
     * so the SVG polyline always renders a continuous line; if you need to
     * distinguish "no data" from "all-fail" check {@see DomainOverviewResult::$totalReports}
     * on the same row before rendering.
     *
     * Single SQL round-trip across all domains — `generate_series` builds a
     * full per-bucket grid which is left-joined to each (domain, bucket)
     * pair, so callers passing 5 domain IDs get one query, not five.
     *
     * @param list<string> $domainIds monitored-domain UUIDs to load trends for
     * @param list<string> $teamIds   team UUIDs the caller is allowed to read from
     *
     * @return array<string, list<float>> map of domain UUID → list of pass-rate buckets, oldest → newest
     */
    public function forDomains(array $domainIds, array $teamIds, int $days = 30): array
    {
        if ([] === $domainIds || [] === $teamIds) {
            return [];
        }

        // Bucket size is fixed at 3 days so the default 30-day window yields a
        // crisp 10-point sparkline. The CASE pass-rate expression mirrors the
        // sibling `forDomain` query for consistency.
        $bucketSize = 3;

        $bucketCount = (int) ceil($days / $bucketSize);

        /** @var list<array{domain_id: string, bucket_index: int|string, pass_rate: float|string}> $data */
        $data = $this->database->executeQuery(
            'WITH buckets AS (
                SELECT bucket_index, bucket_start, bucket_end FROM (
                    SELECT
                        i AS bucket_index,
                        (NOW()::date - make_interval(days => (:days - i * :bucket)::int)) AS bucket_start,
                        (NOW()::date - make_interval(days => (:days - (i + 1) * :bucket)::int)) AS bucket_end
                    FROM generate_series(0, :bucketMax) AS i
                ) sub
            )
            SELECT
                md.id::text AS domain_id,
                b.bucket_index AS bucket_index,
                COALESCE(
                    SUM(CASE WHEN rec.dkim_result = :pass OR rec.spf_result = :pass THEN rec.count ELSE 0 END)::float
                    / NULLIF(SUM(rec.count), 0)
                    * 100,
                    0
                ) AS pass_rate
            FROM monitored_domain md
            CROSS JOIN buckets b
            LEFT JOIN dmarc_report dr
                ON dr.monitored_domain_id = md.id
                AND dr.date_range_end >= b.bucket_start
                AND dr.date_range_begin < b.bucket_end
            LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            WHERE md.id IN (:domainIds)
                AND md.team_id IN (:teamIds)
            GROUP BY md.id, b.bucket_index
            ORDER BY md.id, b.bucket_index',
            [
                'domainIds' => $domainIds,
                'teamIds' => $teamIds,
                'pass' => 'pass',
                'days' => $days,
                'bucket' => $bucketSize,
                'bucketMax' => $bucketCount - 1,
            ],
            [
                'domainIds' => ArrayParameterType::STRING,
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $result = [];
        foreach ($data as $row) {
            $domainId = $row['domain_id'];
            $result[$domainId] ??= [];
            $result[$domainId][] = (float) $row['pass_rate'];
        }

        return $result;
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
