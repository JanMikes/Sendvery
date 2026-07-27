<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Headline counts that sit above the Top Senders chart on the domain detail
 * page, plus the numbers behind the "N senders are waiting for your review"
 * call to action. Each count is rendered as a click-through into the sender
 * inventory.
 *
 * The unreviewed/rejected split is deliberate: "nobody has decided yet" is a
 * request for action, "reviewed and rejected" is a settled decision, and the
 * page has to say which is which. See {@see \App\Value\SenderReviewState}.
 */
final readonly class DomainSenderAuthorizationSummary
{
    public function __construct(
        public int $authorizedCount,
        public int $needsReviewCount,
        public int $notAuthorizedCount,
        public int $uniqueIpCount,
        /**
         * Lifetime message volume attributable to the needs-review senders.
         * Materiality, not just a count: one unreviewed sender carrying most of
         * the domain's mail matters more than ten that sent once.
         */
        public int $needsReviewMessages,
    ) {
    }

    /**
     * @param array{authorized_count: int|string, needs_review_count: int|string, not_authorized_count: int|string, unique_ip_count: int|string, needs_review_messages: int|string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            authorizedCount: (int) $row['authorized_count'],
            needsReviewCount: (int) $row['needs_review_count'],
            notAuthorizedCount: (int) $row['not_authorized_count'],
            uniqueIpCount: (int) $row['unique_ip_count'],
            needsReviewMessages: (int) $row['needs_review_messages'],
        );
    }

    /**
     * Everything that is not authorized, matching the legacy
     * `?filter=unauthorized` set.
     */
    public function unauthorizedCount(): int
    {
        return $this->needsReviewCount + $this->notAuthorizedCount;
    }

    public function hasAnySenders(): bool
    {
        return $this->uniqueIpCount > 0 || $this->authorizedCount > 0 || $this->unauthorizedCount() > 0;
    }
}
