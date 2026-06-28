<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * Emitted the first time the managed-DMARC CNAME is confirmed to resolve to Sendvery (null->set transition of cnameVerifiedAt). Triggers the 'managed DMARC is live' email.
 */
final readonly class CnameVerified
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
    ) {
    }
}
