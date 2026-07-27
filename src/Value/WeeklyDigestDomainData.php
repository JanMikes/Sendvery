<?php

declare(strict_types=1);

namespace App\Value;

final readonly class WeeklyDigestDomainData
{
    /**
     * @param list<WeeklyDigestNewSender> $newSenders senders seen for the first time this week, one row per sender identity
     */
    public function __construct(
        public string $domainName,
        public int $totalMessages,
        /**
         * Messages that passed DKIM or SPF this week.
         *
         * Carried as a count, not just as the percentage below, because the
         * team-wide headline rate has to be message-weighted. Averaging
         * per-domain percentages let one domain sending a single message move
         * the headline by 33 points and printed 97.9% where the truth was
         * 96.5% (DEC-059 D2).
         */
        public int $passedMessages,
        /**
         * Pass rate for the week, or null when no DMARC records landed in the
         * window. Never 0.0 for "no data" — a reader cannot tell a brand-new
         * domain from one failing every message if both print "0%".
         */
        public ?float $passRate,
        /** Week-over-week change; null unless BOTH weeks have real data. */
        public ?float $passRateDelta,
        public array $newSenders,
        /** Needed to deep-link the digest at this domain's filtered sender list. */
        public string $domainId,
        public WeeklyDigestSenderReview $senderReview,
    ) {
    }

    /**
     * True when there is a pass rate worth printing. False means the domain
     * received nothing this week — render the waiting/no-data wording instead.
     */
    public function hasPassRateData(): bool
    {
        return null !== $this->passRate;
    }
}
