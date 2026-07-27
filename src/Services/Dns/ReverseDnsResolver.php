<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Forward and reverse name lookups for a sending IP.
 *
 * Behind an interface for two reasons: the suite must never make a real network
 * request, and the production implementation is bounded in a way that only
 * makes sense against a live resolver. Tests get FakeReverseDnsResolver, wired
 * as the default implementation under `when@test` in config/services.php —
 * exactly like SmtpProbe and Spatie\Dns\Dns.
 *
 * The forward half is here rather than in a resolver of its own because it
 * exists solely to confirm the reverse half: a PTR record is written by whoever
 * controls the reverse zone of an IP block, so it is a claim, not a fact, until
 * the claimed hostname is resolved back to the same address
 * ({@see ForwardConfirmedReverseDns}).
 *
 * Implementations MUST be bounded: no unbounded blocking, and every failure
 * mode reported as null / an empty list rather than thrown. Callers treat those
 * as "no usable record" and cache that answer.
 */
interface ReverseDnsResolver
{
    /**
     * @return string|null the PTR hostname without a trailing dot, or null when
     *                     the IP has no usable reverse record
     */
    public function resolve(string $ip): ?string;

    /**
     * Every address the hostname itself publishes.
     *
     * Both address families, and the complete RRset of each: a legitimate relay
     * may publish AAAA and no A at all, and a gateway may answer from any node
     * of a rotating pool. Returning one family, or one record, would reject
     * hosts that are perfectly genuine.
     *
     * @return list<string> A and AAAA addresses, in whatever textual form the
     *                      resolver produced them; empty when the hostname does
     *                      not resolve
     */
    public function forwardAddresses(string $hostname): array;
}
