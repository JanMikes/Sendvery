<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Exceptions\AiNotYetPurchasable;
use App\Value\BillingInterval;
use App\Value\SubscriptionPlan;

/**
 * Maps (plan, interval) → the Stripe **lookup key** for that price. Twelve
 * mappings total: three paid tiers × two AI variants × two cadences.
 *
 * We key on Stripe lookup keys rather than price IDs because lookup keys are
 * stable, human-readable, and identical across the sandbox and live catalogs
 * — so the exact same code works in both modes with no per-environment price
 * ID env vars to keep in sync. The runtime price ID is resolved from the key
 * via the Stripe Prices API in `SubscriptionManager::resolvePriceId`.
 *
 * Free and Unlimited never have prices (no Stripe). AI variants throw
 * `AiNotYetPurchasable` when `ANTHROPIC_API_KEY` is unset so callers can
 * redirect with an error flash instead of letting the unbuyable tier
 * 500 at Stripe checkout (DEC-057).
 */
final readonly class StripePriceResolver
{
    public function __construct(
        private bool $aiPurchasable = false,
    ) {
    }

    public function getLookupKey(SubscriptionPlan $plan, BillingInterval $interval): string
    {
        if (SubscriptionPlan::Free === $plan) {
            throw new \LogicException('Free plan does not have a Stripe price.');
        }

        if (SubscriptionPlan::Unlimited === $plan) {
            throw new \LogicException('Unlimited plan is internal-only and cannot be purchased via Stripe.');
        }

        if ($plan->hasAi() && !$this->aiPurchasable) {
            throw new AiNotYetPurchasable($plan);
        }

        return sprintf('sendvery_%s_%s', $this->planSegment($plan), $interval->value);
    }

    private function planSegment(SubscriptionPlan $plan): string
    {
        return match ($plan) {
            SubscriptionPlan::Personal => 'personal',
            SubscriptionPlan::PersonalAi => 'personal_ai',
            SubscriptionPlan::Pro => 'pro',
            SubscriptionPlan::ProAi => 'pro_ai',
            SubscriptionPlan::Business => 'business',
            SubscriptionPlan::BusinessAi => 'business_ai',
            // Free/Unlimited are guarded above.
            SubscriptionPlan::Free, SubscriptionPlan::Unlimited => throw new \LogicException('Unreachable.'),
        };
    }
}
