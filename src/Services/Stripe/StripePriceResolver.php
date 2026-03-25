<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Value\SubscriptionPlan;

final readonly class StripePriceResolver
{
    /** @param array<string, string> $priceMap */
    public function __construct(
        private array $priceMap = [],
    ) {
    }

    public function getPriceId(SubscriptionPlan $plan): string
    {
        if (isset($this->priceMap[$plan->value])) {
            return $this->priceMap[$plan->value];
        }

        return match ($plan) {
            SubscriptionPlan::Personal => $this->requireEnv('STRIPE_PRICE_PERSONAL'),
            SubscriptionPlan::Team => $this->requireEnv('STRIPE_PRICE_TEAM'),
            SubscriptionPlan::Free => throw new \LogicException('Free plan does not have a Stripe price.'),
        };
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
