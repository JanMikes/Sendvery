<?php

declare(strict_types=1);

namespace App\Results;

final readonly class AlertDetailResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $alertId,
        public string $type,
        public string $severity,
        public string $title,
        public string $message,
        public array $data,
        public bool $isRead,
        public string $createdAt,
        public ?string $domainId,
        public ?string $domainName,
    ) {
    }

    /**
     * @param array{alert_id: string, type: string, severity: string, title: string, message: string, data: string, is_read: bool|string, created_at: string, domain_id: string|null, domain_name: string|null} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            alertId: $row['alert_id'],
            type: $row['type'],
            severity: $row['severity'],
            title: $row['title'],
            message: $row['message'],
            data: json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR),
            isRead: (bool) $row['is_read'],
            createdAt: $row['created_at'],
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
        );
    }
}
