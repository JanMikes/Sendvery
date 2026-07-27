<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DnswlListing;

/**
 * Whether dnswl.org lists an address as a known-good mail source
 * (DEC-060 WP-F, RFC 8904).
 *
 * Behind an interface for the same two reasons {@see ReverseDnsResolver} and
 * {@see AsnResolver} are: the suite must never make a real network request, and
 * the production implementation is bounded in a way that only makes sense
 * against a live resolver. Tests get {@see FakeDnswlResolver}, wired as the
 * default under `when@test` in config/services.php.
 *
 * Implementations MUST be bounded and MUST report every failure mode as null.
 * "Not listed" is by far the common answer and is an ordinary one.
 */
interface DnswlResolver
{
    public function lookup(string $ip): ?DnswlListing;
}
