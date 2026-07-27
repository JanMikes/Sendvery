<?php

declare(strict_types=1);

namespace App\Value;

final readonly class WeeklyDigestDomainData
{
    /**
     * @param list<string> $newSenders senders seen for the first time this week
     */
    public function __construct(
        public string $domainName,
        public int $totalMessages,
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
