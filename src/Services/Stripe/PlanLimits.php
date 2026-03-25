<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Value\SubscriptionPlan;

final readonly class PlanLimits
{
    public function getMaxDomains(SubscriptionPlan $plan): int
    {
        return match ($plan) {
            SubscriptionPlan::Free => 1,
            SubscriptionPlan::Personal => 5,
            SubscriptionPlan::Team => 50,
        };
    }

    public function getMaxTeamMembers(SubscriptionPlan $plan): int
    {
        return match ($plan) {
            SubscriptionPlan::Free => 1,
            SubscriptionPlan::Personal => 1,
            SubscriptionPlan::Team => 10,
        };
    }

    public function hasFeature(SubscriptionPlan $plan, string $feature): bool
    {
        return match ($feature) {
            'dns_monitoring', 'alerts' => SubscriptionPlan::Free !== $plan,
            'digest' => true,
            'api_access' => SubscriptionPlan::Team === $plan,
            'blacklist_monitoring' => SubscriptionPlan::Free !== $plan,
            'ai_insights' => SubscriptionPlan::Team === $plan,
            'pdf_export' => SubscriptionPlan::Free !== $plan,
            'sender_inventory' => SubscriptionPlan::Free !== $plan,
            default => false,
        };
    }
}
