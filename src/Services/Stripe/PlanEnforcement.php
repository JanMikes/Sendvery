<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Value\SubscriptionPlan;
use Doctrine\DBAL\Connection;

final readonly class PlanEnforcement
{
    public function __construct(
        private PlanLimits $planLimits,
        private Connection $database,
    ) {
    }

    public function canAddDomain(string $teamId, SubscriptionPlan $plan): bool
    {
        $currentCount = $this->getDomainCount($teamId);

        return $currentCount < $this->planLimits->getMaxDomains($plan);
    }

    public function canAddTeamMember(string $teamId, SubscriptionPlan $plan): bool
    {
        $currentCount = $this->getTeamMemberCount($teamId);

        return $currentCount < $this->planLimits->getMaxTeamMembers($plan);
    }

    public function canAccessFeature(SubscriptionPlan $plan, string $feature): bool
    {
        return $this->planLimits->hasFeature($plan, $feature);
    }

    public function getDomainCount(string $teamId): int
    {
        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM monitored_domain WHERE team_id = :teamId',
            ['teamId' => $teamId],
        )->fetchOne();
    }

    public function getTeamMemberCount(string $teamId): int
    {
        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM team_membership WHERE team_id = :teamId',
            ['teamId' => $teamId],
        )->fetchOne();
    }
}
