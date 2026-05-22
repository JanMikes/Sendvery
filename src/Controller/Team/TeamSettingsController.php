<?php

declare(strict_types=1);

namespace App\Controller\Team;

use App\Entity\User;
use App\Repository\TeamInvitationRepository;
use App\Repository\TeamMembershipRepository;
use App\Repository\TeamRepository;
use App\Security\TeamVoter;
use App\Services\DashboardContext;
use App\Value\TeamRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TeamSettingsController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly TeamRepository $teamRepository,
        private readonly TeamMembershipRepository $membershipRepository,
        private readonly TeamInvitationRepository $invitationRepository,
    ) {
    }

    #[Route('/app/team', name: 'team_settings', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $team = $this->teamRepository->get($teamId);

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $currentMembership = $this->membershipRepository->findMembership($currentUser->id, $teamId);
        assert(null !== $currentMembership);

        $members = $this->membershipRepository->findForTeam($teamId);
        $pendingInvitations = $this->invitationRepository->findPendingForTeam($teamId);

        $canManage = $this->isGranted(TeamVoter::MANAGE_MEMBERS, $team);
        $canTransfer = $this->isGranted(TeamVoter::TRANSFER_OWNERSHIP, $team);

        return $this->render('team/settings.html.twig', [
            'team' => $team,
            'currentMembership' => $currentMembership,
            'members' => $members,
            'pendingInvitations' => $pendingInvitations,
            'canManage' => $canManage,
            'canTransfer' => $canTransfer,
            'assignableRoles' => [TeamRole::Member, TeamRole::Admin],
        ]);
    }
}
