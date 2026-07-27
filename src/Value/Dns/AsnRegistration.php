<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * The autonomous system an address is announced from (DEC-060 WP-D).
 *
 * ASN is worth having because it comes from a place the sender does not write.
 * A PTR record lives in the reverse zone of an IP block, and every VPS provider
 * hands that field to the customer; an AS number comes from BGP and the RIR
 * that allocated it. Renting a box does not let you claim `AS8075 Microsoft`.
 *
 * It is nonetheless an **identity** axis and not a trust one, and the
 * distinction is not pedantry: the security gateways this work exists to
 * recognise run on rented cloud capacity, so `eu.cloud-sec-av.com` announces
 * from Amazon's AS and not from any AS bearing its own name. Requiring the ASN
 * to "agree" with the PTR would therefore reject exactly the genuine forwarders
 * it was meant to corroborate. What ASN does buy is a name for a host that has
 * no PTR at all — the case where the alternative is a bare address.
 */
final readonly class AsnRegistration
{
    public function __construct(
        public int $number,
        /**
         * The registry's name for the AS, as published. Null when the number
         * resolved but the name lookup did not — an answer we have, beside one
         * we do not.
         */
        public ?string $organization = null,
    ) {
    }

    /**
     * How the address's network is described to a human when nothing else
     * identified it. Deliberately reads as a network attribution rather than as
     * an identity: "AS16509 Amazon" says which network announced the address,
     * which is true, where a bare "Amazon" would say Amazon sent the mail,
     * which is not.
     */
    public function label(): string
    {
        return null === $this->organization
            ? sprintf('AS%d', $this->number)
            : sprintf('AS%d %s', $this->number, $this->organization);
    }
}
