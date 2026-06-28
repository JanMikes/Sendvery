<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Cron/safety-only: pause the auto-ramp with a human reason (regression, lost
 * CNAME, readiness regressed before a scheduled advance). Carries no teamId —
 * the handler loads via get().
 */
final readonly class PauseAutoRamp
{
    public function __construct(
        public UuidInterface $domainId,
        public string $reason,
    ) {
    }
}
