<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\Services\OnboardingTracker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OnboardingCompleteController extends AbstractController
{
    public function __construct(
        private readonly OnboardingTracker $onboardingTracker,
    ) {
    }

    #[Route('/app/onboarding/complete', name: 'onboarding_complete', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (null === $user->onboardingCompletedAt) {
            $this->onboardingTracker->completeOnboarding($user);
        }

        return $this->render('onboarding/complete.html.twig');
    }
}
