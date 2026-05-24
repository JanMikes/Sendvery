<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Headline counts that sit above the Top Senders chart on the domain detail
 * page. Each count is rendered as a click-through into the sender inventory.
 */
final readonly class DomainSenderAuthorizationSummary
{
    public function __construct(
        public int $authorizedCount,
        public int $unknownCount,
        public int $uniqueIpCount,
    ) {
    }

    /**
     * @param array{authorized_count: int|string, unknown_count: int|string, unique_ip_count: int|string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            authorizedCount: (int) $row['authorized_count'],
            unknownCount: (int) $row['unknown_count'],
            uniqueIpCount: (int) $row['unique_ip_count'],
        );
    }
}
