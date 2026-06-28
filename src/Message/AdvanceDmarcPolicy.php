<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\Dns\PolicyChangeSource;
use Ramsey\Uuid\UuidInterface;

/**
 * Advance to the next DMARC tier (layer 2 — guided one-click, and the auto-ramp
 * executor). Dispatched by both the user (Guided) and the cron (AutoRamp), so it
 * keeps teamId; the handler re-evaluates readiness server-side and is a no-op
 * unless eligible (never trusts the caller).
 */
final readonly class AdvanceDmarcPolicy
{
    public function __construct(
        public UuidInterface $domainId,
        public string $teamId,
        public ?UuidInterface $actorUserId,
        public PolicyChangeSource $source = PolicyChangeSource::Guided,
    ) {
    }
}
