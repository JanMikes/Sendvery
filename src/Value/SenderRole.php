<?php

declare(strict_types=1);

namespace App\Value;

/**
 * What a sending IP actually *is*, as opposed to what its authentication
 * results happen to look like in one report (DEC-059 §3.3).
 *
 * The distinction matters because a recipient-side gateway that forwards a
 * legitimate message necessarily breaks SPF — and breaks DKIM too when it
 * rewrites the body. Judged on auth results alone it is indistinguishable from
 * a spoofer, which is exactly how the 2026-07-27 digest ended up telling a user
 * to "fix misconfigured sending sources" that were never misconfigured.
 */
enum SenderRole: string
{
    /** Authorized by the team, or otherwise proven to be the domain's own outbound relay. */
    case OwnRelay = 'own_relay';

    /** A recognised email service provider (OrganizationMapper hit). */
    case Esp = 'esp';

    /** A recipient-side security gateway, mailing list, or alias/forwarding service. */
    case Forwarder = 'forwarder';

    /** Nothing identified it yet — worth a human glance, but not an accusation. */
    case Unknown = 'unknown';

    /** Fails every authentication method at volume, with no forwarding explanation. */
    case Suspicious = 'suspicious';

    /**
     * Short human label for dashboards, digests and alert copy.
     */
    public function label(): string
    {
        return match ($this) {
            self::OwnRelay => 'Your sending infrastructure',
            self::Esp => 'Email service provider',
            self::Forwarder => 'Forwarder or mail gateway',
            self::Unknown => 'Unrecognised sender',
            self::Suspicious => 'Suspicious sender',
        };
    }

    /**
     * Whether discovering this sender is worth interrupting the user for.
     *
     * Own relays, providers and forwarders are normal mail flow — they belong in
     * the weekly digest as line items, never in a warning. Only senders we could
     * not explain (Unknown) or that look like abuse (Suspicious) earn an alert.
     * This is what stops a rotating IPv6 relay pool from generating a dozen
     * "new unknown sender" warnings in a single day (DEC-059 §3.6).
     */
    public function warrantsAlert(): bool
    {
        return match ($this) {
            self::OwnRelay, self::Esp, self::Forwarder => false,
            self::Unknown, self::Suspicious => true,
        };
    }
}
