<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\SenderIdentity;
use App\Value\Dns\AsnRegistration;

/**
 * What SenderIdentityResolver hands back: the cached network facts for one IP
 * plus the role for *this* caller's context (DEC-059 §3.2, §3.3).
 *
 * It is a value object rather than the entity because {@see $role} may differ
 * from the globally cached baseline once per-team signals are applied — an IP
 * the team authorized is OwnRelay for them and merely an Esp for everybody else.
 */
final readonly class ResolvedSender
{
    public function __construct(
        public string $sourceIp,
        public ?string $hostname,
        public ?string $registrableDomain,
        public ?string $organization,
        public SenderRole $role,
        /**
         * The network announcing the address (DEC-060 WP-D). Never part of
         * {@see identityKey()} — two unrelated senders renting from the same
         * cloud share an AS, and grouping on it would merge them.
         */
        public ?AsnRegistration $asn = null,
    ) {
    }

    public static function fromIdentity(SenderIdentity $identity, ?SenderRole $role = null): self
    {
        return new self(
            sourceIp: $identity->sourceIp,
            hostname: $identity->hostname,
            registrableDomain: $identity->registrableDomain,
            organization: $identity->organization,
            role: $role ?? $identity->role,
            asn: $identity->asnRegistration(),
        );
    }

    /**
     * An IP we have not been able to look up yet — no facts, no accusation.
     */
    public static function unresolved(string $sourceIp): self
    {
        return new self(
            sourceIp: $sourceIp,
            hostname: null,
            registrableDomain: null,
            organization: null,
            role: SenderRole::Unknown,
        );
    }

    /**
     * The grouping key for "is this the same sender?" (DEC-059 §3.2).
     *
     * Identity is the registrable domain of the PTR, never the IP: Seznam's
     * rotating IPv6 pool is fifteen addresses and one sender, and grouping by IP
     * turned that into an endless stream of "new sender" alerts (D5). Falls back
     * to hostname, then the raw IP, so an unresolvable host still groups with
     * itself rather than collapsing into a shared bucket.
     */
    public function identityKey(): string
    {
        return $this->registrableDomain ?? $this->hostname ?? $this->sourceIp;
    }

    /**
     * What a human should see. Prefers the curated organisation name, then the
     * registrable domain — which works for the gateways nobody has mapped yet
     * (cloud-sec-av.com, inkyphishfence.com) — and falls back to the address
     * itself when nothing named the host.
     *
     * In that last case the announcing network is appended rather than
     * substituted: "203.0.113.9 (AS16509 Amazon)" says whose network the
     * address sits in, which is true and useful, where a bare "Amazon" would
     * say Amazon sent the mail — a claim about a host with no PTR record that
     * nothing supports and that would read as an endorsement.
     */
    public function displayLabel(): string
    {
        $named = $this->organization ?? $this->registrableDomain ?? $this->hostname;

        if (null !== $named) {
            return $named;
        }

        return null === $this->asn
            ? $this->sourceIp
            : sprintf('%s (%s)', $this->sourceIp, $this->asn->label());
    }

    public function isResolved(): bool
    {
        return null !== $this->hostname;
    }
}
