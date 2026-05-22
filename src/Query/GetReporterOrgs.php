<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Distinct reporter organisations that have ever sent a DMARC report
 * for any of the team's monitored domains. Used to populate the
 * "Reporter" multiselect on the reports filter bar.
 */
final readonly class GetReporterOrgs
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return list<string>
     */
    public function forTeams(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<string> $rows */
        $rows = $this->database->executeQuery(
            'SELECT DISTINCT dr.reporter_org
            FROM dmarc_report dr
            JOIN monitored_domain md ON md.id = dr.monitored_domain_id
            WHERE md.team_id IN (:teamIds)
            ORDER BY dr.reporter_org ASC',
            [
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchFirstColumn();

        return array_values($rows);
    }
}
