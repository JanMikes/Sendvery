<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\SmtpProbeResult;

/**
 * Test-environment SmtpProbe. Returns "unreachable" for every IP by default,
 * which matches the most common "host is fake / not configured" test scenario
 * without touching the network. Tests that want to assert against a reachable
 * SMTP server script per-IP results via with*().
 */
final class FakeSmtpProbe implements SmtpProbe
{
    /** @var array<string, SmtpProbeResult> */
    private array $results = [];

    public function withReachable(string $ip, ?bool $tlsSupported = true): self
    {
        $this->results[$ip] = new SmtpProbeResult(reachable: true, tlsSupported: $tlsSupported);

        return $this;
    }

    public function withUnreachable(string $ip): self
    {
        $this->results[$ip] = SmtpProbeResult::unreachable();

        return $this;
    }

    public function reset(): void
    {
        $this->results = [];
    }

    public function probe(string $ip): SmtpProbeResult
    {
        return $this->results[$ip] ?? SmtpProbeResult::unreachable();
    }
}
