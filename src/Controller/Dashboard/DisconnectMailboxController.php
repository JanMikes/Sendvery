<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Exceptions\MailboxConnectionNotFound;
use App\Message\DisconnectMailbox;
use App\Repository\MailboxConnectionRepository;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TASK-133: soft-delete a mailbox on behalf of the user. POST-only with CSRF +
 * inline team-ownership check (mirrors {@see RetestMailboxConnectionController}
 * — no Symfony voter needed since the only acl rule is "the team owns the
 * mailbox", which one boolean equality covers). Dispatches
 * {@see DisconnectMailbox}, which idempotently stamps `disconnectedAt`. On
 * success the user lands back on `/app/mailboxes` with a flash; the disconnected
 * row no longer appears because `findByTeam()` filters `disconnectedAt = null`.
 */
final class DisconnectMailboxController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MailboxConnectionRepository $mailboxConnectionRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/mailboxes/{id}/disconnect', name: 'dashboard_mailbox_disconnect', methods: ['POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('mailbox_disconnect', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('Mailbox not found.');
        }

        try {
            $connection = $this->mailboxConnectionRepository->get(Uuid::fromString($id));
        } catch (MailboxConnectionNotFound) {
            throw $this->createNotFoundException('Mailbox not found.');
        }

        if (!$connection->team->id->equals($this->dashboardContext->getTeamId())) {
            throw $this->createNotFoundException('Mailbox not found.');
        }

        $this->commandBus->dispatch(new DisconnectMailbox($connection->id));

        $this->addFlash('success', sprintf('Disconnected %s. Sendvery will no longer poll this mailbox.', $connection->host));

        return $this->redirectToRoute('dashboard_mailboxes');
    }
}
