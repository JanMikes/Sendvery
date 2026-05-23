<?php

declare(strict_types=1);

namespace App\Results;

final readonly class MutedAlertResult
{
    public function __construct(
        public string $mutedAlertId,
        public string $domainId,
        public string $domainName,
        public string $alertType,
        public string $mutedAt,
    ) {
    }

    /**
     * @param array{muted_alert_id: string, domain_id: string, domain_name: string, alert_type: string, muted_at: string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            mutedAlertId: $row['muted_alert_id'],
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            alertType: $row['alert_type'],
            mutedAt: $row['muted_at'],
        );
    }
}
