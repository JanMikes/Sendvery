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
        public float $passRate,
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

        return new self(
            id: (string) $row['id'],
            sourceIp: $row['source_ip'],
            hostname: $row['hostname'],
            organization: $row['organization'],
            label: $row['label'],
            isAuthorized: $isAuthorized,
            firstSeenAt: $row['first_seen_at'],
            lastSeenAt: $row['last_seen_at'],
            totalMessages: (int) $row['total_messages'],
            passRate: (float) $row['pass_rate'],
            updatedAt: $row['updated_at'],
            notes: $row['notes'],
            updatedByUserEmail: $row['updated_by_user_email'],
            reviewState: SenderReviewState::fromFlags($isAuthorized, null !== $row['updated_at']),
        );
    }
}
