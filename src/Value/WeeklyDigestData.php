<?php

declare(strict_types=1);

namespace App\Value;

final readonly class WeeklyDigestData
{
    /**
     * @param array<WeeklyDigestDomainData>   $domains
     * @param list<WeeklyDigestAlertItem>     $attentionAlerts    capped, most severe first; resolved + Success alerts excluded
     * @param list<WeeklyDigestBrokenDnsItem> $currentlyBrokenDns latest per (domain, check type) where isValid=false
     */
    public function __construct(
        public string $teamName,
        public \DateTimeImmutable $periodStart,
        public \DateTimeImmutable $periodEnd,
        public array $domains,
        public int $totalDomains,
        public int $totalMessages,
        /**
         * Mean pass rate across domains that actually reported, or null when no
         * domain did. Null renders as "—", never as 0%.
         */
        public ?float $averagePassRate,
        /**
         * Total number of still-unresolved, non-Success alerts raised in the
         * window. Counts individual alerts, so it is >= the number of rows in
         * `$attentionAlerts` (which are grouped and capped) and drives the
         * "showing N of M" line plus the link to the full list.
         */
        public int $alertsCount,
        /**
         * How many (domain, type) groups the attention alerts collapse into,
         * before the display cap. `count($attentionAlerts) < this` means the
         * email is hiding groups.
         */
        public int $attentionAlertGroups,
        public array $attentionAlerts,
        /**
         * Alerts whose underlying problem was observed fixed during the window.
         * Reported as good news so the digest isn't a wall of complaints.
         */
        public int $resolvedAlertsCount,
        public int $dnsChangesCount,
        public array $currentlyBrokenDns = [],
    ) {
    }

    /**
     * True when the attention list is showing fewer groups than exist, i.e. the
     * "view all" link is load-bearing rather than decorative.
     */
    public function hasMoreAttentionAlerts(): bool
    {
        return count($this->attentionAlerts) < $this->attentionAlertGroups;
    }

    /**
     * Senders across all domains that nobody has decided about yet — the
     * headline number for the digest's "waiting for your review" section.
     * Derived rather than stored so the section can never disagree with the
     * per-domain rows printed underneath it.
     */
    public function sendersAwaitingReviewCount(): int
    {
        return array_sum(array_map(
            static fn (WeeklyDigestDomainData $domain): int => $domain->senderReview->needsReviewCount,
            $this->domains,
        ));
    }
}
