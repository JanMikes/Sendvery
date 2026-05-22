<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Value\SubscriptionPlan;

/**
 * Thrown by `PlanGatedAiInsightsService` when an AI operation is invoked
 * against a team whose plan doesn't include AI. The UI catches this and
 * renders an "Upgrade to add AI Insights" nudge.
 */
final class AiNotEnabledForPlan extends \DomainException
{
    public function __construct(public readonly SubscriptionPlan $plan)
    {
        parent::__construct(sprintf(
            'AI Insights is not available on the "%s" plan — upgrade to an AI variant first.',
            $plan->value,
        ));
    }
}
