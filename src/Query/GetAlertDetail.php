<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\AlertDetailResult;
use Doctrine\DBAL\Connection;

final readonly class GetAlertDetail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forAlert(string $alertId): ?AlertDetailResult
    {
        $row = $this->database->executeQuery(
            'SELECT
                a.id AS alert_id,
                a.type,
                a.severity,
                a.title,
                a.message,
                a.data,
                a.is_read,
                a.created_at,
                md.id AS domain_id,
                md.domain AS domain_name
            FROM alert a
            LEFT JOIN monitored_domain md ON md.id = a.monitored_domain_id
            WHERE a.id = :alertId',
            ['alertId' => $alertId],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return AlertDetailResult::fromDatabaseRow($row);
    }
}
