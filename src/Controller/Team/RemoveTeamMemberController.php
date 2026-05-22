<?php

declare(strict_types=1);

namespace App\Controller\Team;

use App\Exceptions\CannotRemoveTeamOwner;
use App\Message\RemoveTeamMember;
use App\Repository\TeamMembershipRepository;
use App\Repository\TeamRepository;
use App\Security\TeamVoter;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RemoveTeamMemberController extends AbstractController
{
    public function __construct(
        private readonly TeamRepository $teamRepository,
        private readonly TeamMembershipRepository $membershipRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/team/members/{id}/remove', name: 'team_remove_member', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(string $id): Response
    {
        $membership = $this->membershipRepository->get(Uuid::fromString($id));
        $team = $this->teamRepository->get($membership->team->id);

        $this->denyAccessUnlessGranted(TeamVoter::MANAGE_MEMBERS, $team);

        try {
            $this->commandBus->dispatch(new RemoveTeamMember($membership->id));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            $message = $previous instanceof CannotRemoveTeamOwner
                ? $previous->getMessage()
                : 'Something went wrong removing this teammate.';
            $this->addFlash('team_error', $message);

            return $this->redirectToRoute('team_settings');
        }

        $this->addFlash('team_success', sprintf('Removed %s from the team.', $membership->user->email));

        return $this->redirectToRoute('team_settings');
    }
}
