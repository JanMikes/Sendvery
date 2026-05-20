<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class OnboardingTracker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private Connection $database,
    ) {
    }

    public function isOnboardingComplete(User $user): bool
    {
        return null !== $user->onboardingCompletedAt;
    }

    public function completeOnboarding(User $user): void
    {
        $user->onboardingCompletedAt = $this->clock->now();
        $this->entityManager->flush();
    }

    public function completeTeamStep(User $user): void
    {
        if (null === $user->onboardingTeamCompletedAt) {
            $user->onboardingTeamCompletedAt = $this->clock->now();
        }
    }

    public function nextStepRoute(User $user): string
    {
        $hasDomain = $this->userHasMonitoredDomain($user);

        // A user with a domain has implicitly finished the team step — they
        // couldn't have reached the add-domain form without first being routed
        // through (and submitting) step 1 in some earlier session.
        if (null === $user->onboardingTeamCompletedAt && !$hasDomain) {
            return 'onboarding_team';
        }

        if (!$hasDomain) {
            return 'onboarding_domain';
        }

        if (null === $user->onboardingCompletedAt) {
            return 'onboarding_ingestion';
        }

        return 'dashboard_overview';
    }

    public function userHasMonitoredDomain(User $user): bool
    {
        $count = (int) $this->database->fetchOne(
            'SELECT COUNT(d.id)
             FROM monitored_domain d
             INNER JOIN team_membership tm ON tm.team_id = d.team_id
             WHERE tm.user_id = :userId',
            ['userId' => $user->id->toString()],
        );

        return $count > 0;
    }
}
