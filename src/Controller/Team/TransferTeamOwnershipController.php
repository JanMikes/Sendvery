<?php

declare(strict_types=1);

namespace App\Controller\Team;

use App\Entity\User;
use App\Exceptions\CannotTransferOwnership;
use App\Message\TransferTeamOwnership;
use App\Repository\TeamRepository;
use App\Security\TeamVoter;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TransferTeamOwnershipController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly TeamRepository $teamRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/team/transfer', name: 'team_transfer_ownership', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $team = $this->teamRepository->get($teamId);
        $this->denyAccessUnlessGranted(TeamVoter::TRANSFER_OWNERSHIP, $team);

        $newOwnerIdString = $request->request->getString('new_owner_user_id');
        if ('' === $newOwnerIdString) {
            $this->addFlash('team_error', 'Pick the teammate you want to make the new Owner.');

            return $this->redirectToRoute('team_settings');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $this->commandBus->dispatch(new TransferTeamOwnership(
                teamId: $teamId,
                newOwnerUserId: Uuid::fromString($newOwnerIdString),
                currentOwnerUserId: $currentUser->id,
            ));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            $message = $previous instanceof CannotTransferOwnership
                ? $previous->getMessage()
                : 'Something went wrong transferring ownership.';
            $this->addFlash('team_error', $message);

            return $this->redirectToRoute('team_settings');
        }

        $this->addFlash('team_success', 'Ownership transferred. You are now an Admin.');

        return $this->redirectToRoute('team_settings');
    }
}
