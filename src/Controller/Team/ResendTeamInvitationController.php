<?php

declare(strict_types=1);

namespace App\Controller\Team;

use App\Message\ResendTeamInvitation;
use App\Repository\TeamInvitationRepository;
use App\Repository\TeamRepository;
use App\Security\TeamVoter;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ResendTeamInvitationController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly TeamRepository $teamRepository,
        private readonly TeamInvitationRepository $invitationRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/team/invitations/{id}/resend', name: 'team_resend_invitation', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id): Response
    {
        $invitation = $this->invitationRepository->findForTeams(
            Uuid::fromString($id),
            $this->dashboardContext->getTeamIds(),
        );

        if (null === $invitation) {
            throw $this->createNotFoundException('Invitation not found.');
        }

        $team = $this->teamRepository->get($invitation->team->id);

        $this->denyAccessUnlessGranted(TeamVoter::MANAGE_MEMBERS, $team);

        $this->commandBus->dispatch(new ResendTeamInvitation($invitation->id));

        $this->addFlash('team_success', sprintf('Re-sent invitation to %s.', $invitation->invitedEmail));

        return $this->redirectToRoute('team_settings');
    }
}
