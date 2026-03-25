<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\AlertListResult;
use Doctrine\DBAL\Connection;

final readonly class GetAlerts
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<AlertListResult>
     */
    public function forTeam(
        string $teamId,
        ?string $severity = null,
        ?string $type = null,
        ?string $domainId = null,
        ?bool $isRead = null,
        int $limit = 50,
    ): array {
        $sql = 'SELECT
                a.id AS alert_id,
                a.type,
                a.severity,
                a.title,
                a.message,
                a.is_read,
                a.created_at,
                md.id AS domain_id,
                md.domain AS domain_name
            FROM alert a
            LEFT JOIN monitored_domain md ON md.id = a.monitored_domain_id
            WHERE a.team_id = :teamId';

        $params = ['teamId' => $teamId];

        if (null !== $severity) {
            $sql .= ' AND a.severity = :severity';
            $params['severity'] = $severity;
        }

        if (null !== $type) {
            $sql .= ' AND a.type = :type';
            $params['type'] = $type;
        }

        if (null !== $domainId) {
            $sql .= ' AND a.monitored_domain_id = :domainId';
            $params['domainId'] = $domainId;
        }

        if (null !== $isRead) {
            $sql .= ' AND a.is_read = :isRead';
            $params['isRead'] = $isRead ? 'true' : 'false';
        }

        $sql .= ' ORDER BY a.created_at DESC LIMIT :limit';
        $params['limit'] = $limit;

        $rows = $this->database->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(AlertListResult::fromDatabaseRow(...), $rows);
    }

    public function countUnreadForTeam(string $teamId): int
    {
        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM alert WHERE team_id = :teamId AND is_read = false',
            ['teamId' => $teamId],
        )->fetchOne();
    }
}
