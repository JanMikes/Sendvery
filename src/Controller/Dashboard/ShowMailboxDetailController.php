<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetMailboxDetail;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowMailboxDetailController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetMailboxDetail $getMailboxDetail,
    ) {
    }

    #[Route('/app/mailboxes/{id}', name: 'dashboard_mailbox_detail', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $mailbox = $this->getMailboxDetail->forMailbox($id, $teamIds);

        if (null === $mailbox) {
            throw $this->createNotFoundException('Mailbox not found.');
        }

        $recentEnvelopes = $this->getMailboxDetail->recentEnvelopesForMailbox($id);

        return $this->render('dashboard/mailbox_detail.html.twig', [
            'mailbox' => $mailbox,
            'recentEnvelopes' => $recentEnvelopes,
        ]);
    }
}
