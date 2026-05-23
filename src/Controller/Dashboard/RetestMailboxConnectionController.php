<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Exceptions\MailboxConnectionNotFound;
use App\Repository\MailboxConnectionRepository;
use App\Services\DashboardContext;
use App\Services\Mail\MailClient;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inline "Re-test connection" action on the Mailboxes list. Lets the user
 * verify a previously-saved connection on demand instead of waiting for
 * the next 15-minute poll cycle to surface "Active" / "Error". Uses the
 * same `MailClient::testConnection()` seam the poller does — so a real
 * IMAP login + INBOX status check, with the credentials decrypted in-
 * scope via `CredentialEncryptor`.
 */
final class RetestMailboxConnectionController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MailboxConnectionRepository $mailboxConnectionRepository,
        private readonly MailClient $mailClient,
    ) {
    }

    #[Route('/app/mailboxes/{id}/test', name: 'dashboard_mailbox_retest', methods: ['POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('mailbox_retest', $request->request->getString('_csrf_token'))) {
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

        $result = $this->mailClient->testConnection($connection);

        if ($result->success) {
            $this->addFlash('success', 'Connection is working.');
        } else {
            // Do not surface $result->error in the flash — IMAP server error
            // strings often include the bound username or other credential
            // fragments. The classified humanMessage is safe; raw detail
            // belongs in logs, not the UI / session.
            $this->addFlash('error', 'Connection failed: '.($result->errorCode?->humanMessage() ?? 'Unknown error.'));
        }

        return $this->redirectToRoute('dashboard_mailboxes');
    }
}
