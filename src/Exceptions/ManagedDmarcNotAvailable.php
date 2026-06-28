<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Value\SubscriptionPlan;

/**
 * Thrown by the managed-DMARC command handlers when a non-entitled team tries
 * to enable, tighten, or turn on auto-drive. Defense-in-depth so a forged POST
 * can't bypass the controller-level plan gate (DEC-058c). The UI gates earlier
 * and renders an upgrade nudge.
 */
final class ManagedDmarcNotAvailable extends \DomainException
{
    public function __construct(public readonly SubscriptionPlan $plan)
    {
        parent::__construct(sprintf(
            'Managed DMARC is not available on the "%s" plan — upgrade to a paid plan first.',
            $plan->value,
        ));
    }
}
