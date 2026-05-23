<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Message\MarkSenderAuthorized;
use App\Repository\KnownSenderRepository;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RevokeSenderController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly KnownSenderRepository $knownSenderRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{domainId}/senders/{senderId}/revoke', name: 'dashboard_sender_revoke', methods: ['POST'])]
    public function __invoke(string $domainId, string $senderId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('sender_action', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $teamId = $this->dashboardContext->getTeamId();

        $sender = $this->knownSenderRepository->findForTeam(Uuid::fromString($senderId), $teamId);

        if (null === $sender) {
            throw $this->createNotFoundException('Sender not found.');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        $this->commandBus->dispatch(new MarkSenderAuthorized(
            senderId: $sender->id,
            isAuthorized: false,
            actorUserId: $user->id,
        ));

        $this->addFlash('success', 'Sender marked as unknown.');

        return $this->redirectToRoute('dashboard_sender_inventory', ['domainId' => $domainId]);
    }
}
