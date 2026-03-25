<?php

declare(strict_types=1);

namespace App\Results;

final readonly class AlertListResult
{
    public function __construct(
        public string $alertId,
        public string $type,
        public string $severity,
        public string $title,
        public string $message,
        public bool $isRead,
        public string $createdAt,
        public ?string $domainId,
        public ?string $domainName,
    ) {
    }

    /**
     * @param array{alert_id: string, type: string, severity: string, title: string, message: string, is_read: bool, created_at: string, domain_id: ?string, domain_name: ?string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            alertId: $row['alert_id'],
            type: $row['type'],
            severity: $row['severity'],
            title: $row['title'],
            message: $row['message'],
            isRead: (bool) $row['is_read'],
            createdAt: $row['created_at'],
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
        );
    }
}
