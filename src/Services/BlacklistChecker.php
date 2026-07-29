<?php

declare(strict_types=1);

namespace App\Services;

use App\Value\BlacklistListing;
use App\Value\BlacklistListingStatus;
use App\Value\BlacklistResult;

/**
 * DNSBL lookups for a single IP.
 *
 * THE RESPONSE IS A CODE, NOT A YES/NO. A DNS blocklist answers a query in one
 * of three ways, and only one of them means "listed":
 *
 *  - NXDOMAIN / no answer      → not listed. The overwhelmingly common case.
 *  - an A record in 127.0.0/24 → listed, and the final octet says why.
 *  - an A record in 127.255.255/24 → THE LIST REFUSED THE QUERY. Nothing was
 *    looked up. This is the convention Spamhaus and others use to signal
 *    "you are querying via a public resolver", "you have exceeded your quota"
 *    or "your DQS key is wrong".
 *
 * This class used to treat "any A record at all" as a listing, which made the
 * third case indistinguishable from the second. Because lily resolves through
 * Hetzner's shared recursive resolvers, Spamhaus classified every query as
 * coming from an open resolver and answered `127.255.255.254` — so
 * `zen.spamhaus.org` and `cbl.abuseat.org` (also Spamhaus-operated) reported a
 * listing for EVERY IP ever checked, on the public blacklist-checker tool as
 * well as in customers' daily Critical alerts. Spamhaus's own web checker said
 * those IPs were clean, and querying Spamhaus directly from lily's own address
 * returned NXDOMAIN, confirming the listings never existed.
 *
 * The classification below is deliberately general rather than a per-list table
 * of documented codes: a list that adds a new listing code should still be read
 * as "listed", and the failure modes we must catch (rejected query, hijacked
 * NXDOMAIN) are the same shape on every list.
 */
final readonly class BlacklistChecker
{
    /** @var array<string> */
    private const array DNSBLS = [
        'zen.spamhaus.org',
        'b.barracudacentral.org',
        'dnsbl.sorbs.net',
        'bl.spamcop.net',
        'cbl.abuseat.org',
        'dnsbl-1.uceprotect.net',
        'psbl.surriel.com',
        'dnsbl.dronebl.org',
    ];

    /**
     * Accepts a domain or IP. Resolves a domain to its A record before checking.
     * Returns null when the host cannot be resolved.
     */
    public function checkHostOrIp(string $hostOrIp): ?BlacklistResult
    {
        $hostOrIp = trim($hostOrIp);

        if (false !== filter_var($hostOrIp, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return $this->check($hostOrIp);
        }

        $ip = gethostbyname($hostOrIp);

        if ($ip === $hostOrIp || false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return null;
        }

        return $this->check($ip);
    }

    public function check(string $ipAddress): BlacklistResult
    {
        // IPv6 is queried in reverse-nibble form, not dotted-quad. Reversing on
        // dots produces a syntactically valid but meaningless hostname, every
        // list answers NXDOMAIN, and the address is reported CLEAN on all eight
        // — a false all-clear on the one signal blacklist monitoring exists to
        // give. `checkHostOrIp()` already refuses IPv6; the cron path calls
        // this method directly and did not.
        //
        // Saying "not checked" is the honest answer until per-list IPv6 support
        // is implemented: of the eight lists here only some publish an IPv6
        // zone, so a blanket nibble query would swap this false negative for a
        // set of unanswerable lookups.
        if (false !== filter_var($ipAddress, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return new BlacklistResult(
                ipAddress: $ipAddress,
                listings: array_map(
                    static fn (string $dnsbl): BlacklistListing => new BlacklistListing(
                        dnsbl: $dnsbl,
                        status: BlacklistListingStatus::CheckFailed,
                        reason: 'Sendvery does not check IPv6 addresses against this blocklist yet. This is a gap in our coverage, not a finding against the address.',
                    ),
                    self::DNSBLS,
                ),
            );
        }

        $reversedIp = implode('.', array_reverse(explode('.', $ipAddress)));
        $listings = [];

        foreach (self::DNSBLS as $dnsbl) {
            $listings[] = $this->query($reversedIp.'.'.$dnsbl, $dnsbl);
        }

        return new BlacklistResult(
            ipAddress: $ipAddress,
            listings: $listings,
        );
    }

    private function query(string $lookup, string $dnsbl): BlacklistListing
    {
        $records = @dns_get_record($lookup, DNS_A);

        // `false` is a resolver-level failure (SERVFAIL, timeout, no network).
        // That is emphatically not "clean" — we asked and never found out.
        if (false === $records) {
            return new BlacklistListing(
                dnsbl: $dnsbl,
                status: BlacklistListingStatus::CheckFailed,
                reason: 'The lookup did not complete — the blocklist did not respond.',
            );
        }

        if ([] === $records) {
            return new BlacklistListing($dnsbl, BlacklistListingStatus::NotListed);
        }

        $returnCode = null;
        foreach ($records as $record) {
            if (isset($record['ip']) && \is_string($record['ip'])) {
                $returnCode = $record['ip'];

                break;
            }
        }

        if (null === $returnCode) {
            return new BlacklistListing(
                dnsbl: $dnsbl,
                status: BlacklistListingStatus::CheckFailed,
                reason: 'The blocklist answered without a usable address.',
            );
        }

        $status = $this->classify($returnCode);

        return new BlacklistListing(
            dnsbl: $dnsbl,
            status: $status,
            reason: $this->describe($status, $returnCode, $lookup),
            returnCode: $returnCode,
        );
    }

    /**
     * Map a DNSBL return code to a verdict.
     *
     * Anything outside 127.0.0.0/8 means the answer did not come from the
     * blocklist's own numbering scheme — in practice a resolver that
     * synthesises records for NXDOMAIN (ISP "search help" pages do this) or a
     * hijacked lookup. Treating that as a listing would blame the user for
     * their resolver.
     */
    private function classify(string $returnCode): BlacklistListingStatus
    {
        if (!str_starts_with($returnCode, '127.')) {
            return BlacklistListingStatus::CheckFailed;
        }

        // The universal "your query was rejected" block. Spamhaus documents
        // .252 (open resolver), .254 (no/!invalid DQS key) and .255 (volume
        // limit exceeded); the whole /24 is reserved for errors by convention.
        if (str_starts_with($returnCode, '127.255.255.')) {
            return BlacklistListingStatus::CheckFailed;
        }

        // RFC 5782 reserves 127.0.0.1 for the "must NOT be listed" test entry,
        // so no list uses it as a listing code — but a resolver looping a query
        // back to localhost lands here. Ambiguous, and this class errs towards
        // admitting ignorance rather than raising a Critical.
        if ('127.0.0.1' === $returnCode) {
            return BlacklistListingStatus::CheckFailed;
        }

        return BlacklistListingStatus::Listed;
    }

    private function describe(BlacklistListingStatus $status, string $returnCode, string $lookup): ?string
    {
        if (BlacklistListingStatus::CheckFailed === $status) {
            // The list's own TXT record explains the rejection far better than
            // we can ("Error: open resolver; https://check.spamhaus.org/..."),
            // so prefer it and fall back to naming the code we saw.
            return $this->fetchTxt($lookup)
                ?? sprintf('The blocklist rejected our query (returned %s) instead of answering it.', $returnCode);
        }

        return $this->fetchTxt($lookup);
    }

    private function fetchTxt(string $lookup): ?string
    {
        $records = @dns_get_record($lookup, DNS_TXT);

        if (false === $records || [] === $records) {
            return null;
        }

        $txt = $records[0]['txt'] ?? null;

        return \is_string($txt) && '' !== $txt ? $txt : null;
    }

    /**
     * @return array<string>
     */
    public function getDnsblList(): array
    {
        return self::DNSBLS;
    }
}
