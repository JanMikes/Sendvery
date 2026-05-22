<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Value\SubscriptionPlan;

/**
 * Thrown when someone tries to start a checkout for an AI plan variant while
 * `ANTHROPIC_API_KEY` is unset. See DEC-057 — AI variants are gated on the
 * presence of the API key; without it we have no real AI service to charge
 * for.
 *
 * Callers (UpgradePlanController) catch this and redirect the user back to
 * billing with an explanatory flash instead of letting a 500 bubble up.
 */
final class AiNotYetPurchasable extends \DomainException
{
    public function __construct(public readonly SubscriptionPlan $plan)
    {
        parent::__construct(sprintf(
            'AI plan variant "%s" is not yet purchasable — ANTHROPIC_API_KEY is not configured.',
            $plan->value,
        ));
    }
}
