<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\AsnRegistration;

/**
 * Test-environment AsnResolver. Answers "not announced" for every address
 * unless a test scripts one, so nothing in the suite ever touches the network.
 *
 * Aliased as the default AsnResolver under `when@test` in config/services.php,
 * mirroring FakeReverseDnsResolver exactly — including
 * {@see lookupCount()}, which is how a test proves the identity cache asks the
 * network once per address rather than once per report.
 */
final class FakeAsnResolver implements AsnResolver
{
    /** @var array<string, AsnRegistration> */
    private array $registrations = [];

    private int $lookupCount = 0;

    public function withAsn(string $ip, int $number, ?string $organization = null): self
    {
        $this->registrations[$ip] = new AsnRegistration($number, $organization);

        return $this;
    }

    public function reset(): void
    {
        $this->registrations = [];
        $this->lookupCount = 0;
    }

    public function lookupCount(): int
    {
        return $this->lookupCount;
    }

    public function resolve(string $ip): ?AsnRegistration
    {
        ++$this->lookupCount;

        return $this->registrations[$ip] ?? null;
    }
}
