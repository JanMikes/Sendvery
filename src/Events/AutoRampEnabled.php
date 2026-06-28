<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * Emitted when the customer turns auto-drive on for a managed domain.
 */
final readonly class AutoRampEnabled
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
    ) {
    }
}
