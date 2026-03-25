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
        /** @var array{alert_id: string, type: string, severity: string, title: string, message: string, data: string, is_read: bool|string, created_at: string, domain_id: string|null, domain_name: string|null}|false $row */
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
