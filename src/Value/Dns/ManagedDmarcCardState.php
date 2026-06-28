<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * The display state of the dashboard ManagedDmarcCard (DEC-058 §2.5). Derived
 * from the domain's managed fields + entitlement, so the card renders exactly
 * one coherent surface.
 */
enum ManagedDmarcCardState: string
{
    case NotEnabled = 'not_enabled';     // self-TXT — show the "switch to managed" pitch
    case Preparing = 'preparing';        // enabled, hosted record not published yet
    case CnamePending = 'cname_pending'; // hosted record live, CNAME not verified, awaiting propagation
    case Active = 'active';              // CNAME verified — policy selector + advance + auto-drive
    case Error = 'error';               // CNAME lost / ramp tripped — frozen with fix instructions
    case Frozen = 'frozen';             // managed but entitlement lost (downgrade) — read-only
}
