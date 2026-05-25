<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\PassRateAggregate;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Loads the 7-day and 30-day team-wide pass-rate aggregates used by
 * {@see \App\Services\PassRateRegressionAdvisor} to decide whether to show
 * the regression / improvement banner on `/app/reports` (TASK-093).
 *
 * Window bounds come from the injected {@see ClockInterface} so tests can
 * pin a deterministic "now" — the alternative (database NOW()) would force
 * every test to mutate dates relative to wall-clock and produce flakes.
 */
final readonly class GetTeamPassRateAggregates
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array{window7d: PassRateAggregate, baseline30d: PassRateAggregate}
     */
    public function forTeams(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [
                'window7d' => PassRateAggregate::empty(),
                'baseline30d' => PassRateAggregate::empty(),
            ];
        }

        $now = $this->clock->now();
        $sevenDaysAgo = $now->modify('-7 days');
        $thirtyDaysAgo = $now->modify('-30 days');

        $window7d = $this->loadAggregate($teamIds, $sevenDaysAgo, $now);
        $baseline30d = $this->loadAggregate($teamIds, $thirtyDaysAgo, $now);

        return [
            'window7d' => $window7d,
            'baseline30d' => $baseline30d,
        ];
    }

    /**
     * @param list<string> $teamIds
     */
    private function loadAggregate(array $teamIds, \DateTimeImmutable $from, \DateTimeImmutable $to): PassRateAggregate
    {
        /** @var array{pass_rate: float|string|null, report_count: int|string, total_messages: int|string|null, failing_messages: int|string|null}|false $row */
        $row = $this->database->executeQuery(
            "SELECT
                CASE
                    WHEN SUM(rec.count) > 0 THEN
                        SUM(CASE WHEN rec.dkim_result = 'pass' OR rec.spf_result = 'pass' THEN rec.count ELSE 0 END)::float
                        / SUM(rec.count) * 100
                    ELSE NULL
                END AS pass_rate,
                COUNT(DISTINCT dr.id) AS report_count,
                SUM(rec.count) AS total_messages,
                SUM(CASE WHEN rec.dkim_result <> 'pass' AND rec.spf_result <> 'pass' THEN rec.count ELSE 0 END) AS failing_messages
            FROM dmarc_report dr
            JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            WHERE md.team_id IN (:teamIds)
              AND dr.date_range_end >= :from
              AND dr.date_range_end < :to",
            [
                'teamIds' => $teamIds,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $row) {
            return PassRateAggregate::empty();
        }

        return PassRateAggregate::fromDatabaseRow($row);
    }
}
