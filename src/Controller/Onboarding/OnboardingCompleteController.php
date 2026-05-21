<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\Query\GetDomainVerificationStatus;
use App\Repository\TeamMembershipRepository;
use App\Services\DomainVerificationEvaluator;
use App\Services\OnboardingTracker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OnboardingCompleteController extends AbstractController
{
    public function __construct(
        private readonly OnboardingTracker $onboardingTracker,
        private readonly TeamMembershipRepository $teamMembershipRepository,
        private readonly GetDomainVerificationStatus $verificationStatusQuery,
        private readonly DomainVerificationEvaluator $verificationEvaluator,
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

        $memberships = $this->teamMembershipRepository->findForUser($user->id);
        $teamId = $memberships[0]->team->id;
        $status = $this->verificationStatusQuery->forTeam($teamId);

        return $this->render('onboarding/complete.html.twig', [
            'status' => $status,
            'severity' => null === $status ? null : $this->verificationEvaluator->severity($status),
        ]);
    }
}
