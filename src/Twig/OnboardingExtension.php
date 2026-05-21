<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Services\OnboardingTracker;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OnboardingExtension extends AbstractExtension
{
    public function __construct(
        private readonly OnboardingTracker $onboardingTracker,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('onboarding_completed_steps', $this->completedSteps(...)),
        ];
    }

    /** @return array<int, true> */
    public function completedSteps(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return [];
        }

        return $this->onboardingTracker->completedSteps($user);
    }
}
