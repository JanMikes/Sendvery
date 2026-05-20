<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\Repository\TeamMembershipRepository;
use App\Services\OnboardingTracker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OnboardingTeamController extends AbstractController
{
    public function __construct(
        private readonly TeamMembershipRepository $teamMembershipRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly OnboardingTracker $onboardingTracker,
    ) {
    }

    #[Route('/app/onboarding/team', name: 'onboarding_team', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (null !== $user->onboardingCompletedAt) {
            return $this->redirectToRoute('dashboard_overview');
        }

        $memberships = $this->teamMembershipRepository->findForUser($user->id);
        $team = $memberships[0]->team ?? null;

        if ($request->isMethod('POST')) {
            $teamName = trim($request->request->getString('team_name'));

            if ('' !== $teamName && null !== $team) {
                $team->name = $teamName;
            }

            $this->onboardingTracker->completeTeamStep($user);
            $this->entityManager->flush();

            return $this->redirectToRoute('onboarding_domain');
        }

        return $this->render('onboarding/team.html.twig', [
            'teamName' => null === $team ? '' : $team->name,
        ]);
    }
}
