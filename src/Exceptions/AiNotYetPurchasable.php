<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Value\SubscriptionPlan;

/**
 * Thrown when someone tries to start a checkout for an AI plan variant while
 * AI Insights is still gated behind `SENDVERY_AI_PURCHASABLE=false`. See
 * DEC-057 — ship the gating + stubs first, sell the AI plans once real
 * Anthropic inference is ready.
 *
 * Callers (UpgradePlanController) should catch this and redirect the user to
 * the AI-curious lead capture instead of bubbling a server error.
 */
final class AiNotYetPurchasable extends \DomainException
{
    public function __construct(public readonly SubscriptionPlan $plan)
    {
        parent::__construct(sprintf(
            'AI plan variant "%s" is not yet purchasable — SENDVERY_AI_PURCHASABLE is false.',
            $plan->value,
        ));
    }
}
