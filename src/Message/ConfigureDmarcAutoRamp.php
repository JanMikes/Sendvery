<?php

declare(strict_types=1);

namespace App\Message;

use App\Value\Dns\AutoRampAction;
use Ramsey\Uuid\UuidInterface;

/**
 * Turn auto-drive on/off, or pause/resume it (layer 3). Enabling requires the
 * auto-drive entitlement; the off/pause/resume actions are always allowed.
 */
final readonly class ConfigureDmarcAutoRamp
{
    public function __construct(
        public UuidInterface $domainId,
        public string $teamId,
        public AutoRampAction $action,
    ) {
    }
}
