<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * The customer-facing auto-drive control actions. Enabling requires the
 * auto-drive entitlement; disable/pause/resume are always allowed (turning the
 * ramp off or holding it never tightens enforcement).
 */
enum AutoRampAction: string
{
    case Enable = 'enable';
    case Disable = 'disable';
    case Pause = 'pause';
    case Resume = 'resume';
}
