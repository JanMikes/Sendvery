<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * Emitted when auto-drive is turned off (current policy stays live; never loosened).
 */
final readonly class AutoRampDisabled
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
    ) {
    }
}
