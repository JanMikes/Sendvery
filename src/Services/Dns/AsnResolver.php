<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\AsnRegistration;

/**
 * Which autonomous system announces an address (DEC-060 WP-D).
 *
 * Behind an interface for the same two reasons {@see ReverseDnsResolver} is: the
 * suite must never make a real network request, and the production
 * implementation is bounded in a way that only makes sense against a live
 * resolver. Tests get {@see FakeAsnResolver}, wired as the default
 * implementation under `when@test` in config/services.php.
 *
 * Implementations MUST be bounded: no unbounded blocking, and every failure
 * mode reported as null rather than thrown. A missing answer is an ordinary
 * outcome — plenty of addresses sit in prefixes nobody announces — and callers
 * cache it as such.
 */
interface AsnResolver
{
    /**
     * @return AsnRegistration|null null when the address is not announced, is
     *                              malformed, or the lookup did not answer
     */
    public function resolve(string $ip): ?AsnRegistration;
}
