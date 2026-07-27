<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\SenderReviewMateriality;

/**
 * One domain's worth of "senders nobody has decided about", with the volume
 * figures the notification threshold is judged on.
 *
 * Consumed by `sendvery:senders:review-reminder` and by its email template.
 */
final readonly class DomainSendersAwaitingReview
{
    /**
     * @param list<string> $topSenderNames heaviest unreviewed senders first,
     *                                     de-duplicated by name
     */
    public function __construct(
        public string $domainId,
        public string $domainName,
        public int $needsReviewCount,
        /** Lifetime volume carried by the unreviewed senders. */
        public int $needsReviewMessages,
        /** Volume of the single biggest unreviewed sender — the materiality test's main input. */
        public int $largestSenderMessages,
        /** Lifetime volume across every known sender for the domain, reviewed or not. */
        public int $domainMessages,
        public array $topSenderNames,
        /**
         * Distinct names among the unreviewed senders. Lower than
         * `needsReviewCount` whenever a provider sends from several addresses,
         * and it is what the "+N more" tail counts — the tail follows the chips,
         * and the chips are names.
         */
        public int $distinctNameCount = 0,
    ) {
    }

    /**
     * @param array{domain_id: string, domain_name: string, needs_review_count: int|string, needs_review_messages: int|string, largest_sender_messages: int|string, distinct_name_count: int|string, domain_messages: int|string} $row
     * @param list<string>                                                                                                                                                                                                        $topSenderNames
     */
    public static function fromDatabaseRow(array $row, array $topSenderNames): self
    {
        return new self(
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            needsReviewCount: (int) $row['needs_review_count'],
            needsReviewMessages: (int) $row['needs_review_messages'],
            largestSenderMessages: (int) $row['largest_sender_messages'],
            domainMessages: (int) $row['domain_messages'],
            topSenderNames: $topSenderNames,
            distinctNameCount: (int) $row['distinct_name_count'],
        );
    }

    public function isMaterial(): bool
    {
        return SenderReviewMateriality::isMaterial(
            $this->largestSenderMessages,
            $this->needsReviewMessages,
            $this->domainMessages,
        );
    }

    public function sharePercent(): float
    {
        return SenderReviewMateriality::sharePercent($this->needsReviewMessages, $this->domainMessages);
    }

    public function hasMoreThanNamed(): bool
    {
        return $this->distinctNameCount > count($this->topSenderNames);
    }

    public function unnamedCount(): int
    {
        return max(0, $this->distinctNameCount - count($this->topSenderNames));
    }
}
