<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * A host's entry on dnswl.org, the categorised whitelist of mail sources known
 * not to send spam (DEC-060 WP-F, RFC 8904).
 *
 * Useful here because dnswl lists forwarders and relaying MTAs heavily — that
 * is what a "legitimate mail source that is not the original sender" mostly is
 * — and because the listing is decided by dnswl, not by the host. A sender
 * cannot add itself.
 *
 * Still corroboration and nothing more. It is a statement about the operator's
 * general conduct, not about the message in front of us: a listed relay can
 * forward a spoofed message just as it forwards a genuine one, so a listing may
 * withhold an accusation and may never withhold an alert.
 */
final readonly class DnswlListing
{
    /**
     * dnswl's trust levels, from its `127.0.<category>.<trust>` answer. Only
     * `Medium` and above corroborates anything: `None` is dnswl explicitly
     * saying it has no confidence in the entry, and `Low` is a legacy bucket
     * that carries little more.
     */
    public const int TRUST_NONE = 0;
    public const int TRUST_LOW = 1;
    public const int TRUST_MEDIUM = 2;
    public const int TRUST_HIGH = 3;

    public function __construct(
        public int $trustLevel,
        public int $category,
    ) {
    }

    public function isTrusted(): bool
    {
        return $this->trustLevel >= self::TRUST_MEDIUM;
    }
}
