<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Message\BulkAuthorizeSenders;
use App\Message\BulkMarkSendersUnknown;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class BulkSenderActionController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{domainId}/senders/bulk', name: 'dashboard_sender_bulk', methods: ['POST'])]
    public function __invoke(string $domainId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_sender_action', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $action = $request->request->getString('action');
        if (!in_array($action, ['authorize', 'mark_unknown'], true)) {
            throw $this->createNotFoundException('Unknown bulk action.');
        }

        /** @var array<int, mixed> $rawIds */
        $rawIds = $request->request->all('senderIds');

        /** @var list<UuidInterface> $senderIds */
        $senderIds = [];
        foreach ($rawIds as $rawId) {
            if (!is_string($rawId) || !Uuid::isValid($rawId)) {
                continue;
            }
            $senderIds[] = Uuid::fromString($rawId);
        }

        if ([] === $senderIds) {
            return $this->redirectToRoute('dashboard_sender_inventory', ['domainId' => $domainId]);
        }

        $teamId = $this->dashboardContext->getTeamId();

        $user = $this->getUser();
        assert($user instanceof User);

        if ('authorize' === $action) {
            $this->commandBus->dispatch(new BulkAuthorizeSenders(
                senderIds: $senderIds,
                teamId: $teamId,
                actorUserId: $user->id,
            ));
            $this->addFlash('success', sprintf('Authorized %d sender%s.', count($senderIds), 1 === count($senderIds) ? '' : 's'));
        } else {
            $this->commandBus->dispatch(new BulkMarkSendersUnknown(
                senderIds: $senderIds,
                teamId: $teamId,
                actorUserId: $user->id,
            ));
            $this->addFlash('success', sprintf('Marked %d sender%s as unknown.', count($senderIds), 1 === count($senderIds) ? '' : 's'));
        }

        return $this->redirectToRoute('dashboard_sender_inventory', ['domainId' => $domainId]);
    }
}
