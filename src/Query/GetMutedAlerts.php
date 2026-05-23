<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\MutedAlertResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetMutedAlerts
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<MutedAlertResult>
     */
    public function forTeams(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<array{muted_alert_id: string, domain_id: string, domain_name: string, alert_type: string, muted_at: string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT
                ma.id AS muted_alert_id,
                md.id AS domain_id,
                md.domain AS domain_name,
                ma.alert_type,
                ma.muted_at
            FROM muted_alert ma
            JOIN monitored_domain md ON md.id = ma.monitored_domain_id
            WHERE ma.team_id IN (:teamIds)
            ORDER BY ma.muted_at DESC',
            ['teamIds' => $teamIds],
            ['teamIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return array_map(MutedAlertResult::fromDatabaseRow(...), $rows);
    }
}
