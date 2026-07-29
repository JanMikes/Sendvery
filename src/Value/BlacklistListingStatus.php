<?php

declare(strict_types=1);

namespace App\Value;

/**
 * The outcome of asking ONE DNS blocklist about ONE IP.
 *
 * This is an enum and not a `bool $listed` because the question genuinely has
 * three answers, and collapsing the third into either of the other two is a
 * bug we shipped: "the list refused to answer us" was being read as "listed",
 * which produced a daily Critical `IpBlacklisted` alert for an IP Spamhaus's
 * own checker reported as clean.
 *
 * See `BlacklistChecker::classify()` for how the wire response maps here.
 */
enum BlacklistListingStatus: string
{
    /** The list answered, and the answer was a documented listing code. */
    case Listed = 'listed';

    /** The list answered NXDOMAIN — the definitive "we have nothing on this IP". */
    case NotListed = 'not_listed';

    /**
     * We asked and did not get a usable answer, so we know nothing either way.
     *
     * Never render this as a listing (it panics the user over nothing) and never
     * render it as clean (it is a false all-clear on the one signal they bought).
     * Say we could not check.
     */
    case CheckFailed = 'check_failed';

    public function isListed(): bool
    {
        return self::Listed === $this;
    }
}
