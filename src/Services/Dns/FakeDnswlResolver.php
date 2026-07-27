<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DnswlListing;

/**
 * Test-environment DnswlResolver. Answers "not listed" for every address unless
 * a test scripts one, so nothing in the suite ever touches the network.
 *
 * Aliased as the default DnswlResolver under `when@test` in config/services.php,
 * mirroring FakeReverseDnsResolver and FakeAsnResolver — including
 * {@see lookupCount()}, so a test can prove the identity cache asks once per
 * address rather than once per report.
 */
final class FakeDnswlResolver implements DnswlResolver
{
    /** @var array<string, DnswlListing> */
    private array $listings = [];

    private int $lookupCount = 0;

    public function withListing(string $ip, int $trustLevel, int $category = 2): self
    {
        $this->listings[$ip] = new DnswlListing($trustLevel, $category);

        return $this;
    }

    public function reset(): void
    {
        $this->listings = [];
        $this->lookupCount = 0;
    }

    public function lookupCount(): int
    {
        return $this->lookupCount;
    }

    public function lookup(string $ip): ?DnswlListing
    {
        ++$this->lookupCount;

        return $this->listings[$ip] ?? null;
    }
}
