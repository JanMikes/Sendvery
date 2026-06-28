<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * Dispatched by the sync cron when a domain's `_dmarc` CNAME still points at
 * Sendvery but managed DMARC is off (downgrade/offboard) — or when a verified
 * CNAME disappears. A Critical alert + email tell the customer to re-enable or
 * remove the CNAME so their DMARC keeps working.
 */
final readonly class ManagedDmarcDanglingDetected
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
    ) {
    }
}
