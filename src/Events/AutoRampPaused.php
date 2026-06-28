<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * Emitted when the auto-ramp is paused (by the customer, by a safety rail, or by a downgrade freeze). Carries a human reason for the 'we paused your ramp' email.
 */
final readonly class AutoRampPaused
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
        public string $reason,
    ) {
    }
}
