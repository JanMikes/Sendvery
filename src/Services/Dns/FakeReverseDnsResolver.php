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
 * lookupCount() and forwardLookupCount() exist so tests can assert the two
 * things that matter most about the identity cache: that a second resolution of
 * the same IP performs no second lookup, and that identifying a sender stays
 * inside its per-batch DNS budget.
 */
final class FakeReverseDnsResolver implements ReverseDnsResolver
{
    /** @var array<string, string> */
    private array $hostnames = [];

    /** @var array<string, list<string>> */
    private array $addressesByHostname = [];

    private int $lookupCount = 0;

    private int $forwardLookupCount = 0;

    /**
     * Scripts a genuine host: the address answers with this PTR hostname *and*
     * the hostname resolves back to the address, which is what forward-confirmed
     * reverse DNS demands. Real mail infrastructure looks exactly like this, so
     * every test that only cares about identification keeps saying what it meant.
     *
     * Addresses accumulate, so two IPs scripted onto one hostname become that
     * hostname's RRset — the shape of a rotating relay pool.
     */
    public function withHostname(string $ip, string $hostname): self
    {
        $this->hostnames[$ip] = $hostname;
        $this->addressesByHostname[$hostname] ??= [];

        if (!in_array($ip, $this->addressesByHostname[$hostname], true)) {
            $this->addressesByHostname[$hostname][] = $ip;
        }

        return $this;
    }

    /**
     * Scripts a forged PTR: the reverse zone claims this hostname, but the
     * hostname's own addresses do not include the claiming IP.
     *
     * Whoever holds an IP block writes its reverse zone, so this costs an
     * attacker nothing — it is the exact shape of the attack forward
     * confirmation exists to stop.
     */
    public function withForgedHostname(string $ip, string $hostname): self
    {
        $this->hostnames[$ip] = $hostname;
        $this->addressesByHostname[$hostname] ??= [];

        return $this;
    }

    /**
     * Replaces a hostname's forward RRset outright, for the cases where the
     * addresses it publishes are not simply the ones that claimed it: an
     * AAAA-only relay, a rotating pool, or an IPv4-mapped answer.
     */
    public function withForwardAddresses(string $hostname, string ...$addresses): self
    {
        $this->addressesByHostname[$hostname] = array_values($addresses);

        return $this;
    }

    public function reset(): void
    {
        $this->hostnames = [];
        $this->addressesByHostname = [];
        $this->lookupCount = 0;
        $this->forwardLookupCount = 0;
    }

    public function lookupCount(): int
    {
        return $this->lookupCount;
    }

    public function forwardLookupCount(): int
    {
        return $this->forwardLookupCount;
    }

    public function resolve(string $ip): ?string
    {
        ++$this->lookupCount;

        return $this->hostnames[$ip] ?? null;
    }

    public function forwardAddresses(string $hostname): array
    {
        ++$this->forwardLookupCount;

        return $this->addressesByHostname[$hostname] ?? [];
    }
}
