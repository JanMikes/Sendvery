<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Test-environment ReverseDnsResolver. Returns "no PTR record" for every IP
 * unless a test scripts one, so nothing in the suite ever touches the network.
 *
 * Aliased as the default ReverseDnsResolver under `when@test` in
 * config/services.php, mirroring FakeSmtpProbe and FakeDns. Tests that need a
 * specific scenario fetch this instance from the container and chain
 * ->withHostname() before exercising the subject.
 *
 * lookupCount() exists so tests can assert the thing that matters most about
 * the identity cache: that a second resolution of the same IP performs no
 * second lookup.
 */
final class FakeReverseDnsResolver implements ReverseDnsResolver
{
    /** @var array<string, string> */
    private array $hostnames = [];

    private int $lookupCount = 0;

    public function withHostname(string $ip, string $hostname): self
    {
        $this->hostnames[$ip] = $hostname;

        return $this;
    }

    public function reset(): void
    {
        $this->hostnames = [];
        $this->lookupCount = 0;
    }

    public function lookupCount(): int
    {
        return $this->lookupCount;
    }

    public function resolve(string $ip): ?string
    {
        ++$this->lookupCount;

        return $this->hostnames[$ip] ?? null;
    }
}
