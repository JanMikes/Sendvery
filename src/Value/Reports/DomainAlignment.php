<?php

declare(strict_types=1);

namespace App\Value\Reports;

use App\Value\DmarcAlignment;

/**
 * RFC 7489 §3.1 identifier alignment: does an authenticated identifier (the
 * DKIM `d=` domain, or the SPF envelope-sender domain) line up with the domain
 * in the visible From header?
 *
 * - Strict (`s`): the two domains must be exactly equal.
 * - Relaxed (`r`): they must share an organisational domain.
 *
 * Organisational domain is derived without a Public Suffix List download: the
 * registrable domain is "last two labels", widened to three for the curated
 * multi-label suffixes below (co.uk, com.au, …). A subdomain relation in either
 * direction is also accepted so that deep hierarchies under a suffix we do not
 * know about (e.g. `news.example.pvt.k12.wy.us` vs `example.pvt.k12.wy.us`)
 * still align. The trade-off: two unrelated domains sharing an unlisted
 * multi-label suffix can be reported as aligned. That only ever changes the
 * *explanation* we print for a row ("the signature failed" instead of "the
 * signature was for another domain") — never the pass/fail verdict, which comes
 * from the reporter's own evaluated results.
 */
final readonly class DomainAlignment
{
    /**
     * Curated list of the multi-label public suffixes that actually show up in
     * DMARC aggregate reports. Deliberately short: every entry is a maintenance
     * cost, and the subdomain fallback above covers the long tail.
     *
     * @var list<string>
     */
    private const array MULTI_LABEL_PUBLIC_SUFFIXES = [
        'ac.uk', 'co.uk', 'gov.uk', 'ltd.uk', 'me.uk', 'net.uk', 'org.uk', 'plc.uk', 'sch.uk',
        'com.au', 'edu.au', 'gov.au', 'net.au', 'org.au',
        'ac.nz', 'co.nz', 'govt.nz', 'net.nz', 'org.nz',
        'ac.jp', 'co.jp', 'go.jp', 'ne.jp', 'or.jp',
        'co.kr', 'com.tw', 'com.cn', 'com.hk', 'com.sg', 'com.my', 'co.th', 'co.id', 'com.ph', 'com.vn',
        'co.in', 'com.pk', 'com.bd',
        'com.br', 'com.ar', 'com.mx', 'com.co', 'com.pe', 'com.uy', 'com.ve',
        'co.za', 'com.ng', 'co.ke', 'com.eg', 'com.sa', 'com.tr', 'co.il', 'com.ua',
        'com.es', 'com.pl', 'com.pt', 'com.ru', 'co.at',
    ];

    public static function isAligned(string $identifierDomain, string $headerFrom, DmarcAlignment $mode): bool
    {
        $identifier = self::normalise($identifierDomain);
        $from = self::normalise($headerFrom);

        if ('' === $identifier || '' === $from) {
            return false;
        }

        if ($identifier === $from) {
            return true;
        }

        if (DmarcAlignment::Strict === $mode) {
            return false;
        }

        return str_ends_with($identifier, '.'.$from)
            || str_ends_with($from, '.'.$identifier)
            || self::organisationalDomain($identifier) === self::organisationalDomain($from);
    }

    public static function organisationalDomain(string $domain): string
    {
        $labels = explode('.', self::normalise($domain));

        if (count($labels) <= 2) {
            return implode('.', $labels);
        }

        $lastTwo = implode('.', array_slice($labels, -2));

        if (in_array($lastTwo, self::MULTI_LABEL_PUBLIC_SUFFIXES, true)) {
            return implode('.', array_slice($labels, -3));
        }

        return $lastTwo;
    }

    private static function normalise(string $domain): string
    {
        return strtolower(trim($domain, ". \t\n\r\0\x0B"));
    }
}
