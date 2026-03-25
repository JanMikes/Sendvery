<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Repository\MailboxConnectionRepository;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListMailboxesController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MailboxConnectionRepository $mailboxConnectionRepository,
    ) {
    }

    #[Route('/app/mailboxes', name: 'dashboard_mailboxes')]
    public function __invoke(): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $mailboxes = $this->mailboxConnectionRepository->findByTeam($teamId);

        return $this->render('dashboard/mailboxes.html.twig', [
            'mailboxes' => $mailboxes,
        ]);
    }
}
