<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\SenderReviewState;

final readonly class SenderInventoryResult
{
    public function __construct(
        public string $id,
        public string $sourceIp,
        public ?string $hostname,
        public ?string $organization,
        public ?string $label,
        public bool $isAuthorized,
        public string $firstSeenAt,
        public string $lastSeenAt,
        public int $totalMessages,
        /**
         * NULL when this host has been seen but no message from it was ever
         * counted, i.e. `totalMessages === 0`.
         *
         * Derived here rather than stored: `known_sender.pass_rate` is a
         * non-nullable cache written by {@see \App\Services\SenderDiscovery},
         * whose own `$total > 0 ? … : 0.0` puts a fabricated zero in the column,
         * and `aggregateBySourceIp()` has no `HAVING SUM(rec.count) > 0` while
         * `DmarcXmlParser` defaults a missing `<count>` to 0. `totalMessages`
         * tells us exactly which stored zeroes are fabricated, so the read side
         * can be honest without a migration and without leaving historical rows
         * ambiguous. The inventory table and the exported PDF used to print that
         * zero as a red 0.0% beside a message count of 0.
         */
        public ?float $passRate,
        public ?string $updatedAt,
        public ?string $notes,
        public ?string $updatedByUserEmail,
        /**
         * The user-facing state. `isAuthorized` alone cannot distinguish
         * "nobody has decided yet" from "reviewed and rejected", and those two
         * need different words and different urgency — see
         * {@see SenderReviewState}.
         */
        public SenderReviewState $reviewState,
    ) {
    }

    /** @param array{id: string, source_ip: string, hostname: string|null, organization: string|null, label: string|null, is_authorized: bool|string, first_seen_at: string, last_seen_at: string, total_messages: int|string, pass_rate: float|string, updated_at: string|null, notes: string|null, updated_by_user_email: string|null} $row */
    public static function fromDatabaseRow(array $row): self
    {
        $isAuthorized = (bool) $row['is_authorized'];
        $totalMessages = (int) $row['total_messages'];

        return new self(
            id: (string) $row['id'],
            sourceIp: $row['source_ip'],
            hostname: $row['hostname'],
            organization: $row['organization'],
            label: $row['label'],
            isAuthorized: $isAuthorized,
            firstSeenAt: $row['first_seen_at'],
            lastSeenAt: $row['last_seen_at'],
            totalMessages: $totalMessages,
            passRate: 0 === $totalMessages ? null : (float) $row['pass_rate'],
            updatedAt: $row['updated_at'],
            notes: $row['notes'],
            updatedByUserEmail: $row['updated_by_user_email'],
            reviewState: SenderReviewState::fromFlags($isAuthorized, null !== $row['updated_at']),
        );
    }
}
