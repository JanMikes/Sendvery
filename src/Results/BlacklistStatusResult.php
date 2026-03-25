<?php

declare(strict_types=1);

namespace App\Results;

final readonly class BlacklistStatusResult
{
    /**
     * @param array<string, array{listed: bool, reason: string|null}> $results
     */
    public function __construct(
        public string $id,
        public string $ipAddress,
        public string $checkedAt,
        public array $results,
        public bool $isListed,
    ) {
    }

    /** @param array{id: string, ip_address: string, checked_at: string, results: string, is_listed: bool|string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        /** @var array<string, array{listed: bool, reason: string|null}> $results */
        $results = json_decode($row['results'], true, flags: JSON_THROW_ON_ERROR);

        return new self(
            id: (string) $row['id'],
            ipAddress: $row['ip_address'],
            checkedAt: $row['checked_at'],
            results: $results,
            isListed: (bool) $row['is_listed'],
        );
    }

    public function listedCount(): int
    {
        return count(array_filter($this->results, static fn (array $r) => $r['listed']));
    }
}
