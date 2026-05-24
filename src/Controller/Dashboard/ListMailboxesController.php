<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetMailboxDetail;
use App\Repository\MailboxConnectionRepository;
use App\Results\MailboxActivitySummary;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListMailboxesController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MailboxConnectionRepository $mailboxConnectionRepository,
        private readonly GetMailboxDetail $getMailboxDetail,
    ) {
    }

    #[Route('/app/mailboxes', name: 'dashboard_mailboxes')]
    public function __invoke(): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $mailboxes = $this->mailboxConnectionRepository->findByTeam($teamId);

        // Batch-load the 30-day activity tuple per mailbox so the inline
        // summary cell on the list page doesn't N+1 across mailboxes.
        $mailboxIds = array_values(array_map(static fn ($m) => $m->id->toString(), $mailboxes));
        $activity = $this->getMailboxDetail->summaryForMailboxes($mailboxIds);

        // Fill the gaps so the template can index by mailbox UUID
        // unconditionally — a fresh mailbox with no envelopes still renders
        // a "0 envelopes / 0 reports / 0 quarantined (30d)" line.
        $empty = MailboxActivitySummary::empty();
        foreach ($mailboxIds as $id) {
            if (!array_key_exists($id, $activity)) {
                $activity[$id] = $empty;
            }
        }

        return $this->render('dashboard/mailboxes.html.twig', [
            'mailboxes' => $mailboxes,
            'activity' => $activity,
        ]);
    }
}
