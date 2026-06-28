<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * Emitted when a domain switches to managed-CNAME mode (DEC-058). Triggers the publish-first of the hosted DMARC policy record so the CNAME target exists before the customer points at it.
 */
final readonly class ManagedDmarcEnabled
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
    ) {
    }
}
