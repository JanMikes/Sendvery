<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * Emitted by MonitoredDomain when dmarc_verified_at transitions from null
 * to a value — i.e. the first time we confirmed the customer's DMARC record
 * is published. Listeners release any reports that were quarantined for
 * this domain name while it was unverified.
 */
final readonly class DomainDmarcVerified
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
    ) {
    }
}
