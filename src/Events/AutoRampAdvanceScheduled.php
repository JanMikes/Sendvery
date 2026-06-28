<?php

declare(strict_types=1);

namespace App\Events;

use App\Value\Dns\AutoRampStage;
use Ramsey\Uuid\UuidInterface;

/**
 * Emitted when the auto-ramp cron schedules the next tightening 48h out. Triggers the advance-notice email.
 */
final readonly class AutoRampAdvanceScheduled
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
        public AutoRampStage $to,
        public \DateTimeImmutable $scheduledAt,
    ) {
    }
}
