<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Production ReverseDnsResolver: the system resolver via gethostbyaddr().
 *
 * Timeout, honestly described. gethostbyaddr() accepts no timeout argument and
 * PHP exposes no per-call bound for it, so the ceiling is enforced on two
 * levels:
 *
 * 1. RES_OPTIONS caps the libc resolver itself. glibc reads `timeout:` and
 *    `attempts:` from this variable, which turns the default worst case
 *    (5s x 2 attempts x every nameserver in resolv.conf — tens of seconds) into
 *    roughly two seconds per nameserver. It is best-effort: musl ignores
 *    RES_OPTIONS, and glibc only reads it when the resolver initialises.
 * 2. The guaranteed bound lives one level up, in SenderIdentityResolver, which
 *    performs at most a fixed number of live lookups per batch and defers the
 *    rest to the next ingest. Combined with the global sender_identity cache
 *    that is what actually closes DEC-059 D11 (worker stall on a multi-IP
 *    report) — no single report can chain an unbounded number of lookups, and
 *    an IP is looked up once for the whole system rather than once per report.
 */
final readonly class SystemReverseDnsResolver implements ReverseDnsResolver
{
    private const int LOOKUP_TIMEOUT_SECONDS = 2;

    public function __construct()
    {
        putenv(sprintf('RES_OPTIONS=timeout:%d attempts:1', self::LOOKUP_TIMEOUT_SECONDS));
    }

    public function resolve(string $ip): ?string
    {
        $hostname = @gethostbyaddr($ip);

        // gethostbyaddr() echoes the input back when there is no PTR record,
        // and returns false for a malformed address.
        if (false === $hostname || '' === $hostname || $hostname === $ip) {
            return null;
        }

        return rtrim($hostname, '.');
    }
}
