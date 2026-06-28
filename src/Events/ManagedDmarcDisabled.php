<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * Emitted when managed DMARC is switched off for a domain. The hosted record id is kept so teardown can stay dangling-safe (delete only once the CNAME no longer points at us).
 */
final readonly class ManagedDmarcDisabled
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
        public ?string $hostedRecordId,
    ) {
    }
}
