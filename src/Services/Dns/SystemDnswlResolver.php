<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DnswlListing;

/**
 * Production DnswlResolver, over dnswl.org's DNSxL interface (RFC 8904).
 *
 * One A lookup against the address written backwards under `list.dnswl.org`,
 * answered as `127.0.<category>.<trust>`. Free, no API key, and it reuses the
 * bounded-lookup discipline the other resolvers already live under.
 *
 * `127.0.0.255` is dnswl's "query refused" answer — returned to public
 * resolvers that exceed its limits, which is a state a self-hosted Sendvery can
 * reach without noticing. It is read as *not listed* rather than as a listing
 * with trust 255, because a rate-limited lookup must never be able to soften a
 * verdict. That is the same direction every other failure degrades in.
 */
final readonly class SystemDnswlResolver implements DnswlResolver
{
    private const string ZONE = 'list.dnswl.org';

    /** dnswl's refusal answer, and the only reserved value in the last octet. */
    private const int REFUSED = 255;

    public function lookup(string $ip): ?DnswlListing
    {
        $queryName = $this->queryName($ip);

        if (null === $queryName) {
            return null;
        }

        $records = @dns_get_record($queryName, \DNS_A);

        if (false === $records) {
            return null;
        }

        foreach ($records as $record) {
            $answer = $record['ip'] ?? null;

            if (!is_string($answer)) {
                continue;
            }

            $listing = $this->readAnswer($answer);

            if (null !== $listing) {
                return $listing;
            }
        }

        return null;
    }

    private function readAnswer(string $answer): ?DnswlListing
    {
        $octets = explode('.', $answer);

        if (4 !== count($octets) || '127' !== $octets[0]) {
            return null;
        }

        $trust = (int) $octets[3];

        if (self::REFUSED === $trust) {
            return null;
        }

        return new DnswlListing(trustLevel: $trust, category: (int) $octets[2]);
    }

    /**
     * The DNSxL convention shared with every other list: octets reversed for
     * IPv4, nibbles reversed for IPv6.
     */
    private function queryName(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));

        if (false === $packed) {
            return null;
        }

        if (4 === strlen($packed)) {
            return implode('.', array_reverse(explode('.', inet_ntop($packed) ?: ''))).'.'.self::ZONE;
        }

        return implode('.', array_reverse(str_split(bin2hex($packed)))).'.'.self::ZONE;
    }
}
