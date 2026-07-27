<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\AsnRegistration;

/**
 * Production AsnResolver, over Team Cymru's public DNS interface.
 *
 * Chosen because it is a plain TXT lookup: free, no API key, no account, no new
 * HTTP dependency, and it reuses the same bounded-lookup discipline the reverse
 * resolver already lives under (RES_OPTIONS caps libc, and
 * {@see \App\Services\SenderIdentityResolver} caps identifications per batch).
 * A paid GeoIP database would have bought nothing this does not, at the cost of
 * a licence and a data file to keep fresh.
 *
 * Two queries, and the second is optional:
 *
 *   `4.4.8.8.origin.asn.cymru.com`  TXT → "15169 | 8.8.4.0/24 | US | arin | …"
 *   `AS15169.asn.cymru.com`         TXT → "15169 | US | arin | … | GOOGLE, US"
 *
 * The first carries the number, the second the name. A failed name lookup
 * yields a registration with a number and no organisation rather than nothing —
 * the number is the part that came from BGP, and it is worth keeping on its own.
 */
final readonly class SystemAsnResolver implements AsnResolver
{
    private const string ORIGIN_ZONE_V4 = 'origin.asn.cymru.com';
    private const string ORIGIN_ZONE_V6 = 'origin6.asn.cymru.com';
    private const string AS_NAME_ZONE = 'asn.cymru.com';

    public function resolve(string $ip): ?AsnRegistration
    {
        $queryName = $this->originQueryName($ip);

        if (null === $queryName) {
            return null;
        }

        $origin = $this->firstTxtRecord($queryName);

        if (null === $origin) {
            return null;
        }

        $number = $this->asNumber($origin);

        if (null === $number) {
            return null;
        }

        return new AsnRegistration($number, $this->organizationOf($number));
    }

    /**
     * Cymru keys its zone on the address written backwards: octets for IPv4,
     * nibbles for IPv6, exactly as `in-addr.arpa` and `ip6.arpa` do.
     */
    private function originQueryName(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));

        if (false === $packed) {
            return null;
        }

        if (4 === strlen($packed)) {
            return implode('.', array_reverse(explode('.', inet_ntop($packed) ?: ''))).'.'.self::ORIGIN_ZONE_V4;
        }

        return implode('.', array_reverse(str_split(bin2hex($packed)))).'.'.self::ORIGIN_ZONE_V6;
    }

    /**
     * A prefix announced by more than one AS answers with them space-separated
     * ("701 1239 | …"). The first is as good an answer as any and is what every
     * consumer of this interface treats as *the* origin.
     */
    private function asNumber(string $origin): ?int
    {
        $number = strtok($this->fields($origin)[0], ' ');

        return is_string($number) && ctype_digit($number) ? (int) $number : null;
    }

    private function organizationOf(int $number): ?string
    {
        $answer = $this->firstTxtRecord(sprintf('AS%d.%s', $number, self::AS_NAME_ZONE));

        if (null === $answer) {
            return null;
        }

        // "15169 | US | arin | 2000-03-30 | GOOGLE, US" — the name is last.
        $fields = $this->fields($answer);
        $name = $fields[count($fields) - 1];

        return '' === $name ? null : $name;
    }

    /**
     * @return non-empty-list<string> Cymru's answers are pipe-separated; the
     *                                fields are trimmed because it pads them
     */
    private function fields(string $answer): array
    {
        return array_map(trim(...), explode('|', $answer));
    }

    private function firstTxtRecord(string $name): ?string
    {
        $records = @dns_get_record($name, \DNS_TXT);

        if (false === $records) {
            return null;
        }

        foreach ($records as $record) {
            $txt = $record['txt'] ?? null;

            if (is_string($txt) && '' !== trim($txt)) {
                return $txt;
            }
        }

        return null;
    }
}
