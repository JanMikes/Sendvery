<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\DmarcPolicy;
use App\Value\Dns\PolicyChangeSource;
use Ramsey\Uuid\UuidInterface;

/**
 * Set the managed DMARC policy directly (layer 1 — the instant manual selector,
 * and the cron's rollback target). The source distinguishes a user's Manual
 * change (entitlement-gated) from a system Rollback/DowngradeFreeze (always
 * allowed — loosening/freezing must never be blocked).
 */
final readonly class SetDmarcPolicy
{
    public function __construct(
        public UuidInterface $domainId,
        public string $teamId,
        public ?UuidInterface $actorUserId,
        public DmarcPolicy $p,
        public ?DmarcPolicy $sp,
        public int $pct,
        public PolicyChangeSource $source = PolicyChangeSource::Manual,
    ) {
    }
}
