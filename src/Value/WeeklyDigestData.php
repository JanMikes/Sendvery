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
         * Messages across the whole team that passed DKIM or SPF. Stored rather
         * than a ready-made percentage so {@see overallPassRate()} is the only
         * way to obtain the headline number.
         */
        public int $totalPassedMessages,
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
     * The headline pass rate: message-weighted across the whole team, or null
     * when the team received no messages at all (rendered "—", never 0%).
     *
     * Derived from the two totals on purpose. The shipped digest averaged the
     * per-domain percentages, which claimed 97.9% where the message-weighted
     * truth was 96.5% and let one domain sending a single failing message swing
     * the number by 33 points — while the sentence around it said "sent 57
     * messages … with an overall pass rate of", asserting a weighted figure
     * (DEC-059 D2). Computing it here means the headline cannot disagree with
     * the volume printed beside it.
     */
    public function overallPassRate(): ?float
    {
        return $this->totalMessages > 0
            ? $this->totalPassedMessages / $this->totalMessages * 100
            : null;
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

    /**
     * Senders seen for the first time this week, across all domains — the
     * headline number for the "new senders discovered" section.
     *
     * Lives here rather than being summed with `{% set %}` inside the HTML
     * template: the plain-text alternative and the section registry need the
     * same total, and a number computed inside one renderer is invisible to
     * everything else.
     */
    public function newSendersCount(): int
    {
        return array_sum(array_map(
            static fn (WeeklyDigestDomainData $domain): int => count($domain->newSenders),
            $this->domains,
        ));
    }
}
