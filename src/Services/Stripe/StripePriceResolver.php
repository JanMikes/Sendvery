<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Exceptions\AiNotYetPurchasable;
use App\Value\BillingInterval;
use App\Value\SubscriptionPlan;

/**
 * Maps (plan, interval) → Stripe price ID. Twelve mappings total:
 * three paid tiers × two AI variants × two cadences.
 *
 * Free and Unlimited never have prices (no Stripe). AI variants throw
 * `AiNotYetPurchasable` when `ANTHROPIC_API_KEY` is unset so callers can
 * redirect with an error flash instead of letting the unbuyable tier
 * 500 at Stripe checkout (DEC-057).
 */
final readonly class StripePriceResolver
{
    /** @param array<string, string> $priceMap optional override (test/local) */
    public function __construct(
        private bool $aiPurchasable = false,
        private array $priceMap = [],
    ) {
    }

    public function getPriceId(SubscriptionPlan $plan, BillingInterval $interval): string
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

        $mapKey = $plan->value.'_'.$interval->value;
        if (isset($this->priceMap[$mapKey])) {
            return $this->priceMap[$mapKey];
        }

        return $this->requireEnv($this->envVarFor($plan, $interval));
    }

    private function envVarFor(SubscriptionPlan $plan, BillingInterval $interval): string
    {
        $planSegment = match ($plan) {
            SubscriptionPlan::Personal => 'PERSONAL',
            SubscriptionPlan::PersonalAi => 'PERSONAL_AI',
            SubscriptionPlan::Pro => 'PRO',
            SubscriptionPlan::ProAi => 'PRO_AI',
            SubscriptionPlan::Business => 'BUSINESS',
            SubscriptionPlan::BusinessAi => 'BUSINESS_AI',
            // Free/Unlimited are handled above.
            SubscriptionPlan::Free, SubscriptionPlan::Unlimited => throw new \LogicException('Unreachable.'),
        };

        $intervalSegment = match ($interval) {
            BillingInterval::Monthly => 'MONTHLY',
            BillingInterval::Annual => 'ANNUAL',
        };

        return sprintf('STRIPE_PRICE_%s_%s', $planSegment, $intervalSegment);
    }

    private function requireEnv(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';

        if ('' === $value) {
            throw new \RuntimeException(sprintf('Environment variable "%s" is not set.', $name));
        }

        return $value;
    }
}
