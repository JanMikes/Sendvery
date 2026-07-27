<?php

declare(strict_types=1);

namespace App\Value\Reports;

/**
 * What happened to one authentication mechanism (DKIM or SPF) for a single
 * aggregate-report row, expressed so a non-expert can act on it.
 *
 * Only `Aligned` contributes to a DMARC pass — DMARC needs a mechanism that
 * both authenticated AND whose identifier aligns with the visible From domain.
 */
enum AuthMechanismOutcome: string
{
    /** Authenticated and the identifier aligns with header-from: this is what makes DMARC pass. */
    case Aligned = 'aligned';

    /** The identifier we were told about does not align with header-from. */
    case Misaligned = 'misaligned';

    /** The identifier aligns, but the check itself did not succeed (bad signature, IP not in SPF). */
    case AuthenticationFailed = 'authentication_failed';

    /** Nothing to evaluate — no DKIM signature / no SPF identity was reported. */
    case NotPresent = 'not_present';

    public function label(): string
    {
        return match ($this) {
            self::Aligned => 'Aligned',
            self::Misaligned => 'Not aligned',
            self::AuthenticationFailed => 'Check failed',
            self::NotPresent => 'Not present',
        };
    }

    /** daisyUI semantic tone used for the badge on the report detail page. */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Aligned => 'success',
            self::Misaligned, self::AuthenticationFailed => 'error',
            self::NotPresent => 'ghost',
        };
    }

    public function contributesToDmarcPass(): bool
    {
        return self::Aligned === $this;
    }
}
