<?php

declare(strict_types=1);

namespace App\Results;

final readonly class SenderInventoryResult
{
    public function __construct(
        public string $id,
        public string $sourceIp,
        public ?string $hostname,
        public ?string $organization,
        public ?string $label,
        public bool $isAuthorized,
        public string $firstSeenAt,
        public string $lastSeenAt,
        public int $totalMessages,
        public float $passRate,
    ) {
    }

    /** @param array{id: string, source_ip: string, hostname: ?string, organization: ?string, label: ?string, is_authorized: bool|string, first_seen_at: string, last_seen_at: string, total_messages: int|string, pass_rate: float|string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            sourceIp: $row['source_ip'],
            hostname: $row['hostname'],
            organization: $row['organization'],
            label: $row['label'],
            isAuthorized: (bool) $row['is_authorized'],
            firstSeenAt: $row['first_seen_at'],
            lastSeenAt: $row['last_seen_at'],
            totalMessages: (int) $row['total_messages'],
            passRate: (float) $row['pass_rate'],
        );
    }
}
