<?php

declare(strict_types=1);

namespace App\Controller\Team;

use App\Message\RevokeTeamInvitation;
use App\Repository\TeamInvitationRepository;
use App\Repository\TeamRepository;
use App\Security\TeamVoter;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RevokeTeamInvitationController extends AbstractController
{
    public function __construct(
        private readonly TeamRepository $teamRepository,
        private readonly TeamInvitationRepository $invitationRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/team/invitations/{id}/revoke', name: 'team_revoke_invitation', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id): Response
    {
        $invitation = $this->invitationRepository->get(Uuid::fromString($id));
        $team = $this->teamRepository->get($invitation->team->id);

        $this->denyAccessUnlessGranted(TeamVoter::MANAGE_MEMBERS, $team);

        $this->commandBus->dispatch(new RevokeTeamInvitation($invitation->id));

        $this->addFlash('team_success', sprintf('Invitation to %s revoked.', $invitation->invitedEmail));

        return $this->redirectToRoute('team_settings');
    }
}
