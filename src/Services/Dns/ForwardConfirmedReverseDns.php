<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Forward-confirmed reverse DNS (FCrDNS): does the hostname a PTR record claims
 * actually resolve back to the address that claimed it?
 *
 * This exists because a PTR record proves nothing on its own. The reverse zone
 * of an IP block belongs to whoever holds the block, and essentially every VPS
 * provider lets a customer set the PTR of their own address to any string they
 * like. Sendvery grants the Forwarder role — and with it silence on the
 * new-sender alert — on the strength of that hostname, so an unconfirmed PTR
 * was a free way for a spoofer to switch off the one signal that would have
 * surfaced them: point the reverse record at `anything.mimecast.com` and the
 * alert never fires.
 *
 * The forward direction cannot be forged the same way. Mimecast's A and AAAA
 * records are published by Mimecast, so requiring the original address to
 * appear in the RRset of the claimed hostname turns the claim back into
 * evidence. Google and Yahoo have required exactly this of bulk senders since
 * February 2024, so legitimate mail infrastructure already satisfies it — all
 * 17 sending hosts in production pass.
 *
 * Confirmation gates *trust only*. A hostname that fails is still stored and
 * still displayed, because it remains the best label available for the sender;
 * it simply does not buy the Forwarder role.
 */
final readonly class ForwardConfirmedReverseDns
{
    /**
     * The ::ffff:0:0/96 prefix: ten zero bytes followed by two 0xff bytes.
     *
     * Microsoft's `*.outbound.protection.outlook.com` answers AAAA queries with
     * IPv4-mapped addresses such as `::ffff:40.93.13.100`. Comparing textually
     * would reject that legitimate host, because the DMARC report names the same
     * machine `40.93.13.100`.
     */
    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    public function __construct(
        private ReverseDnsResolver $reverseDns,
    ) {
    }

    public function confirms(string $ip, string $hostname): bool
    {
        $wanted = $this->packed($ip);

        if (null === $wanted) {
            return false;
        }

        foreach ($this->reverseDns->forwardAddresses($hostname) as $candidate) {
            if ($wanted === $this->packed($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An address reduced to the only form two addresses can be compared in.
     *
     * inet_pton() collapses every textual spelling of one address onto one
     * binary value, which string comparison cannot: `2a02:598:64:8a00::1000:904`
     * and `2a02:0598:0064:8a00:0000:0000:1000:0904` are the same host, and so
     * are `40.93.13.100` and `::ffff:40.93.13.100` once the IPv4-mapped prefix
     * is stripped.
     */
    private function packed(string $address): ?string
    {
        $packed = @inet_pton(trim($address));

        if (false === $packed) {
            return null;
        }

        if (16 === strlen($packed) && str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            return substr($packed, 12);
        }

        return $packed;
    }
}
