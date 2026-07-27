<?php

declare(strict_types=1);

namespace App\Value;

/**
 * One row in the digest's "Needs your attention" list.
 *
 * Deliberately a *group*, not a single alert. Detection-driven alert types fire
 * once per detection, so a week of new-sender discoveries on one domain used to
 * produce a dozen near-identical amber rows in the email — the digest looked
 * alarming while saying one thing twelve times. Alerts are collapsed by
 * (domain, type) and `$occurrences` carries the count, so the reader sees
 * "12 new senders detected for sendvery.com" once.
 */
final readonly class WeeklyDigestAlertItem
{
    public function __construct(
        /** Title of the most recent alert in the group. */
        public string $title,
        public AlertSeverity $severity,
        /** Null for team-wide alerts that aren't tied to a domain. */
        public ?string $domainName,
        /** How many alerts this row stands for; 1 means nothing was collapsed. */
        public int $occurrences,
    ) {
    }
}
