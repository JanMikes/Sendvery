<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\Dns\AutoRampStage;
use Ramsey\Uuid\UuidInterface;

/**
 * Cron-only: schedule the next auto-ramp tightening 48h out (fires the advance-
 * notice email). Carries no teamId — dispatched only by the trusted auto-ramp
 * cron, so the handler loads via get().
 */
final readonly class ScheduleAutoRampAdvance
{
    public function __construct(
        public UuidInterface $domainId,
        public AutoRampStage $to,
        public \DateTimeImmutable $at,
    ) {
    }
}
