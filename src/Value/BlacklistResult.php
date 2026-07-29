<?php

declare(strict_types=1);

namespace App\Value;

final readonly class BlacklistResult
{
    /**
     * @param list<BlacklistListing> $listings
     */
    public function __construct(
        public string $ipAddress,
        public array $listings,
    ) {
    }

    /**
     * True only when at least one list returned a genuine listing code.
     *
     * A blocklist that refused our query does NOT count — that is what made
     * every checked IP look blacklisted.
     */
    public function isListed(): bool
    {
        foreach ($this->listings as $listing) {
            if ($listing->status->isListed()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<BlacklistListing> */
    public function listedOn(): array
    {
        return array_values(array_filter(
            $this->listings,
            static fn (BlacklistListing $l): bool => $l->status->isListed(),
        ));
    }

    /** @return list<BlacklistListing> */
    public function unavailable(): array
    {
        return array_values(array_filter(
            $this->listings,
            static fn (BlacklistListing $l): bool => BlacklistListingStatus::CheckFailed === $l->status,
        ));
    }

    public function listedCount(): int
    {
        return count($this->listedOn());
    }

    public function unavailableCount(): int
    {
        return count($this->unavailable());
    }

    public function totalChecked(): int
    {
        return count($this->listings);
    }

    /**
     * How many lists actually gave us an answer we can rely on.
     *
     * The UI needs this to say "clean on 6 of 8 lists — 2 could not be checked"
     * rather than a bare green tick that overstates what we know.
     */
    public function answeredCount(): int
    {
        return $this->totalChecked() - $this->unavailableCount();
    }

    /**
     * Not one list answered, so "clean" would be a fabrication.
     */
    public function isInconclusive(): bool
    {
        return [] !== $this->listings && 0 === $this->answeredCount();
    }

    /**
     * @return array<string, array{status: string, listed: bool, reason: string|null, return_code: string|null}>
     */
    public function toStorageArray(): array
    {
        $stored = [];
        foreach ($this->listings as $listing) {
            $stored[$listing->dnsbl] = $listing->toStorageArray();
        }

        return $stored;
    }
}
