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
            if (!$this->onboardingTracker->userHasMonitoredDomain($user)) {
                return $this->redirectToRoute($this->onboardingTracker->nextStepRoute($user));
            }

            $this->onboardingTracker->completeOnboarding($user);
        }

        // Step 4 is celebration-only: it must never contradict step 3's verification
        // result. Verification banners belong on the dashboard, where they reflect a
        // settled view across daily check runs rather than a fresh, racy DNS query.
        return $this->render('onboarding/complete.html.twig');
    }
}
