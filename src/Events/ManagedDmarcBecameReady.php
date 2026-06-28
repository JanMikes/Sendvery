<?php

declare(strict_types=1);

namespace App\Events;

use App\Value\DmarcPolicy;
use Ramsey\Uuid\UuidInterface;

/**
 * Dispatched by the auto-ramp cron when a guided (auto-drive OFF) managed domain
 * becomes eligible to advance — so we can nudge the customer to advance in one
 * click. Deduped by a recent ManagedDmarcReady alert so we don't nag daily.
 */
final readonly class ManagedDmarcBecameReady
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
        public DmarcPolicy $recommendedTier,
    ) {
    }
}
