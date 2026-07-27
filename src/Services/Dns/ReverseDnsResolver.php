<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Reverse (PTR) lookup for a sending IP.
 *
 * Behind an interface for two reasons: the suite must never make a real network
 * request, and the production implementation is bounded in a way that only
 * makes sense against a live resolver. Tests get FakeReverseDnsResolver, wired
 * as the default implementation under `when@test` in config/services.php —
 * exactly like SmtpProbe and Spatie\Dns\Dns.
 *
 * Implementations MUST be bounded: no unbounded blocking, and every failure
 * mode reported as null rather than thrown. Callers treat null as "no usable
 * reverse record" and cache that answer.
 */
interface ReverseDnsResolver
{
    /**
     * @return string|null the PTR hostname without a trailing dot, or null when
     *                     the IP has no usable reverse record
     */
    public function resolve(string $ip): ?string;
}
