<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Message\SetSenderNote;
use App\Repository\KnownSenderRepository;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class UpdateSenderNoteController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly KnownSenderRepository $knownSenderRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{domainId}/senders/{senderId}/note', name: 'dashboard_sender_note', methods: ['POST'])]
    public function __invoke(string $domainId, string $senderId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('sender_note', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $teamId = $this->dashboardContext->getTeamId();

        $sender = $this->knownSenderRepository->findForTeam(Uuid::fromString($senderId), $teamId);

        if (null === $sender) {
            throw $this->createNotFoundException('Sender not found.');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        $rawNote = $request->request->getString('note');
        $note = '' === $rawNote ? null : $rawNote;

        $this->commandBus->dispatch(new SetSenderNote(
            senderId: $sender->id,
            teamId: $teamId,
            note: $note,
            actorUserId: $user->id,
        ));

        $this->addFlash('success', 'Note saved.');

        return $this->redirectToRoute('dashboard_sender_inventory', ['domainId' => $domainId]);
    }
}
