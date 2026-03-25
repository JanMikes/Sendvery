<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DnsCheckHistoryResult
{
    /**
     * @param array<array{severity: string, message: string, recommendation?: string}> $issues
     * @param array<string, mixed>                                                     $details
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $checkedAt,
        public ?string $rawRecord,
        public bool $isValid,
        public array $issues,
        public array $details,
        public ?string $previousRawRecord,
        public bool $hasChanged,
    ) {
    }

    /**
     * @param array{id: string, type: string, checked_at: string, raw_record: string|null, is_valid: bool|string, issues: string, details: string, previous_raw_record: string|null, has_changed: bool|string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: $row['id'],
            type: $row['type'],
            checkedAt: $row['checked_at'],
            rawRecord: $row['raw_record'],
            isValid: (bool) $row['is_valid'],
            issues: json_decode($row['issues'], true, 512, JSON_THROW_ON_ERROR),
            details: json_decode($row['details'], true, 512, JSON_THROW_ON_ERROR),
            previousRawRecord: $row['previous_raw_record'],
            hasChanged: (bool) $row['has_changed'],
        );
    }
}
