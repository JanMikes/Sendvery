<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DnsHealthOverviewResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetDnsHealthOverview
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Per-domain DNS health snapshot for the in-app DNS Health overview.
     * Uses a LEFT JOIN LATERAL so we get one row per domain together with the
     * latest snapshot (if any) in a single round-trip — avoids the obvious
     * N+1 of calling GetDomainHealthHistory::latestForDomain() per domain.
     *
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<DnsHealthOverviewResult>
     */
    public function forTeams(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<array{domain_id: string, domain_name: string, spf_verified_at: string|null, dkim_verified_at: string|null, dmarc_verified_at: string|null, latest_snapshot_grade: string|null, latest_snapshot_score: int|string|null, latest_spf_score: int|string|null, latest_dkim_score: int|string|null, latest_dmarc_score: int|string|null, latest_mx_score: int|string|null, latest_checked_at: string|null}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT
                md.id           AS domain_id,
                md.domain       AS domain_name,
                md.spf_verified_at,
                md.dkim_verified_at,
                md.dmarc_verified_at,
                dhs.grade       AS latest_snapshot_grade,
                dhs.score       AS latest_snapshot_score,
                dhs.spf_score   AS latest_spf_score,
                dhs.dkim_score  AS latest_dkim_score,
                dhs.dmarc_score AS latest_dmarc_score,
                dhs.mx_score    AS latest_mx_score,
                dhs.checked_at  AS latest_checked_at
            FROM monitored_domain md
            LEFT JOIN LATERAL (
                SELECT grade, score, spf_score, dkim_score, dmarc_score, mx_score, checked_at
                FROM domain_health_snapshot
                WHERE monitored_domain_id = md.id
                ORDER BY checked_at DESC
                LIMIT 1
            ) dhs ON true
            WHERE md.team_id IN (:teamIds)
            ORDER BY md.domain ASC',
            [
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(DnsHealthOverviewResult::fromDatabaseRow(...), $rows);
    }
}
