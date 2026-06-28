<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Switch a domain to managed DMARC (CNAME). The handler seeds the first hosted
 * policy from the customer's current live DMARC record (enforcement-preserving)
 * and publishes it before the customer points the CNAME at us.
 */
final readonly class EnableManagedDmarc
{
    public function __construct(
        public UuidInterface $domainId,
        public string $teamId,
        public ?UuidInterface $actorUserId,
    ) {
    }
}
