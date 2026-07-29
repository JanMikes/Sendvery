<?php

declare(strict_types=1);

namespace App\Value;

/**
 * What one DNS blocklist said about one IP.
 *
 * `returnCode` is the raw `127.x.x.x` A record the list answered with, kept
 * because it is the evidence for the verdict — when a user disputes a listing,
 * "zen.spamhaus.org returned 127.0.0.11" is checkable and "we think you're
 * listed" is not. Null when the list answered NXDOMAIN or nothing at all.
 */
final readonly class BlacklistListing
{
    public function __construct(
        public string $dnsbl,
        public BlacklistListingStatus $status,
        public ?string $reason = null,
        public ?string $returnCode = null,
    ) {
    }

    /**
     * Rehydrate from the `blacklist_check_result.results` JSON column.
     *
     * Rows written before the three-state model carry `{"listed": bool}` with
     * no `status` key. They are read back on the old contract rather than
     * migrated: every historical `listed: true` on a Spamhaus-operated list is
     * probably one of the false positives this class exists to stop, but
     * silently rewriting a stored measurement to what we now wish it had said
     * would destroy the only record of what was actually observed.
     *
     * @param array{status?: string, listed?: bool, reason?: string|null, return_code?: string|null} $row
     */
    public static function fromStorageArray(string $dnsbl, array $row): self
    {
        $status = match (true) {
            isset($row['status']) => BlacklistListingStatus::tryFrom($row['status']) ?? BlacklistListingStatus::CheckFailed,
            true === ($row['listed'] ?? null) => BlacklistListingStatus::Listed,
            false === ($row['listed'] ?? null) => BlacklistListingStatus::NotListed,
            default => BlacklistListingStatus::CheckFailed,
        };

        return new self(
            dnsbl: $dnsbl,
            status: $status,
            reason: $row['reason'] ?? null,
            returnCode: $row['return_code'] ?? null,
        );
    }

    /**
     * @return array{status: string, listed: bool, reason: string|null, return_code: string|null}
     */
    public function toStorageArray(): array
    {
        // `listed` is written alongside `status` so a row stays readable by any
        // consumer still on the old shape — and so the meaning of the legacy
        // key never silently flips: it is true only for a confirmed listing.
        return [
            'status' => $this->status->value,
            'listed' => $this->status->isListed(),
            'reason' => $this->reason,
            'return_code' => $this->returnCode,
        ];
    }
}
