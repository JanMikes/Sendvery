<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\BlacklistListing;
use App\Value\BlacklistListingStatus;

final readonly class BlacklistStatusResult
{
    /**
     * @param list<BlacklistListing> $listings
     */
    public function __construct(
        public string $id,
        public string $ipAddress,
        public string $checkedAt,
        public array $listings,
        public bool $isListed,
    ) {
    }

    /** @param array{id: string, ip_address: string, checked_at: string, results: string, is_listed: bool|string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        /** @var array<string, array{status?: string, listed?: bool, reason?: string|null, return_code?: string|null}> $decoded */
        $decoded = json_decode($row['results'], true, flags: JSON_THROW_ON_ERROR);

        $listings = [];
        foreach ($decoded as $dnsbl => $entry) {
            $listings[] = BlacklistListing::fromStorageArray((string) $dnsbl, $entry);
        }

        return new self(
            id: (string) $row['id'],
            ipAddress: $row['ip_address'],
            checkedAt: $row['checked_at'],
            listings: $listings,
            isListed: (bool) $row['is_listed'],
        );
    }

    public function listedCount(): int
    {
        return count(array_filter(
            $this->listings,
            static fn (BlacklistListing $l): bool => $l->status->isListed(),
        ));
    }

    public function unavailableCount(): int
    {
        return count(array_filter(
            $this->listings,
            static fn (BlacklistListing $l): bool => BlacklistListingStatus::CheckFailed === $l->status,
        ));
    }

    public function totalChecked(): int
    {
        return count($this->listings);
    }

    public function answeredCount(): int
    {
        return $this->totalChecked() - $this->unavailableCount();
    }

    /**
     * Every list refused or failed, so this IP has no verdict — rendering it
     * as "Clean" would invent an all-clear we never received.
     */
    public function isInconclusive(): bool
    {
        return [] !== $this->listings && 0 === $this->answeredCount();
    }
}
