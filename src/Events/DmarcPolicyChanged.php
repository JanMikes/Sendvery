<?php

declare(strict_types=1);

namespace App\Events;

use App\Value\Dns\ManagedDmarcPolicy;
use App\Value\Dns\PolicyChangeSource;
use Ramsey\Uuid\UuidInterface;

/**
 * Emitted whenever the managed (hosted) DMARC policy effectively changes — the single funnel for set / advance / rollback / downgrade-freeze. Drives one republish, one audit row, and (for enforcing tiers) one confirmation email.
 */
final readonly class DmarcPolicyChanged
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
        public ?ManagedDmarcPolicy $from,
        public ManagedDmarcPolicy $to,
        public PolicyChangeSource $source,
        public ?UuidInterface $actorUserId,
    ) {
    }
}
