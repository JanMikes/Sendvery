<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Value\SubscriptionPlan;

/**
 * Canonical source of truth for plan limits and feature gates.
 *
 * Mirrors the matrix in `docs/05-monetization.md`. When these diverge, this
 * class wins for runtime behavior — update the doc to match (or vice versa).
 * `PlanLimitsTest` pins every value, so any change here breaks tests on
 * purpose to force a deliberate review.
 */
final readonly class PlanLimits
{
    public function getMaxDomains(SubscriptionPlan $plan): int
    {
        return match ($plan->baseTier()) {
            SubscriptionPlan::Free => 1,
            SubscriptionPlan::Personal => 5,
            SubscriptionPlan::Pro => 20,
            SubscriptionPlan::Business => 50,
            SubscriptionPlan::Unlimited => PHP_INT_MAX,
            // baseTier() never returns these *Ai cases, but match must be exhaustive.
            SubscriptionPlan::PersonalAi, SubscriptionPlan::ProAi, SubscriptionPlan::BusinessAi => throw new \LogicException('Unreachable: baseTier() collapses AI variants.'),
        };
    }

    public function getMaxTeamMembers(SubscriptionPlan $plan): int
    {
        return match ($plan->baseTier()) {
            SubscriptionPlan::Free => 1,
            SubscriptionPlan::Personal => 1,
            SubscriptionPlan::Pro => 3,
            SubscriptionPlan::Business => 10,
            SubscriptionPlan::Unlimited => PHP_INT_MAX,
            SubscriptionPlan::PersonalAi, SubscriptionPlan::ProAi, SubscriptionPlan::BusinessAi => throw new \LogicException('Unreachable: baseTier() collapses AI variants.'),
        };
    }

    public function getMaxReportsPerMonth(SubscriptionPlan $plan): int
    {
        return match ($plan->baseTier()) {
            SubscriptionPlan::Free => 100,
            SubscriptionPlan::Personal => 1_000,
            SubscriptionPlan::Pro => 10_000,
            SubscriptionPlan::Business => 50_000,
            SubscriptionPlan::Unlimited => PHP_INT_MAX,
            SubscriptionPlan::PersonalAi, SubscriptionPlan::ProAi, SubscriptionPlan::BusinessAi => throw new \LogicException('Unreachable: baseTier() collapses AI variants.'),
        };
    }

    /**
     * Days of retention. null = unlimited (Business and Unlimited).
     */
    public function getRetentionDays(SubscriptionPlan $plan): ?int
    {
        return match ($plan->baseTier()) {
            SubscriptionPlan::Free => 30,
            SubscriptionPlan::Personal => 365,
            SubscriptionPlan::Pro => 730,
            SubscriptionPlan::Business, SubscriptionPlan::Unlimited => null,
            SubscriptionPlan::PersonalAi, SubscriptionPlan::ProAi, SubscriptionPlan::BusinessAi => throw new \LogicException('Unreachable: baseTier() collapses AI variants.'),
        };
    }

    /**
     * Monthly cap of on-demand "Explain this" AI calls. 0 when the plan
     * doesn't include AI. PHP_INT_MAX for the staff-grant Unlimited tier.
     */
    public function getOnDemandAiQuota(SubscriptionPlan $plan): int
    {
        if (SubscriptionPlan::Unlimited === $plan) {
            return PHP_INT_MAX;
        }

        if (!$plan->hasAi()) {
            return 0;
        }

        return match ($plan) {
            SubscriptionPlan::PersonalAi => 50,
            SubscriptionPlan::ProAi => 200,
            SubscriptionPlan::BusinessAi => 500,
            default => 0,
        };
    }

    public function hasFeature(SubscriptionPlan $plan, string $feature): bool
    {
        if (SubscriptionPlan::Unlimited === $plan) {
            return true;
        }

        return match ($feature) {
            'dns_monitoring', 'alerts', 'blacklist_monitoring', 'sender_inventory', 'pdf_export' => SubscriptionPlan::Free !== $plan,
            'digest' => true,
            'api_access' => in_array(
                $plan->baseTier(),
                [SubscriptionPlan::Pro, SubscriptionPlan::Business],
                true,
            ),
            'ai_insights' => $plan->hasAi(),
            'white_label_pdf' => SubscriptionPlan::Business === $plan->baseTier(),
            default => false,
        };
    }
}
