<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class OnboardingTracker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
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
}
