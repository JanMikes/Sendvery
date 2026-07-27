<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Per-domain "senders still waiting for a decision" figures for the weekly
 * digest.
 *
 * Deliberately NOT the same thing as {@see WeeklyDigestDomainData::$newSenders}:
 * that list answers "what appeared this week?" from `dmarc_record` and has no
 * opinion on authorization, so a sender discovered five weeks ago and never
 * reviewed fell out of the digest entirely. This reads real state from
 * `known_sender`, so an unreviewed sender keeps being reported until somebody
 * decides about it.
 */
final readonly class WeeklyDigestSenderReview
{
    /**
     * @param list<string> $topSenderNames    highest-volume unreviewed senders,
     *                                        organisation name where we resolved
     *                                        one, else hostname, else IP —
     *                                        de-duplicated, so a provider running
     *                                        five outbound machines is one chip
     * @param int          $distinctNameCount how many distinct names exist in
     *                                        total; the "+N more" tail counts
     *                                        names, not addresses, because the
     *                                        chips are names
     */
    public function __construct(
        public int $needsReviewCount,
        public int $needsReviewMessages,
        public array $topSenderNames,
        public int $distinctNameCount = 0,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, [], 0);
    }

    public function hasAny(): bool
    {
        return $this->needsReviewCount > 0;
    }

    /**
     * True when the named senders are only a sample of the whole set, i.e. the
     * "+N more" tail is load-bearing.
     */
    public function hasMoreThanNamed(): bool
    {
        return $this->distinctNameCount > count($this->topSenderNames);
    }

    public function unnamedCount(): int
    {
        return max(0, $this->distinctNameCount - count($this->topSenderNames));
    }
}
