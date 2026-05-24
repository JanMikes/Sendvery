<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetMailboxDetail;
use App\Repository\MailboxConnectionRepository;
use App\Results\MailboxActivitySummary;
use App\Services\DashboardContext;
use App\Services\Dns\RuaScenarioResolver;
use App\Services\MailboxHealthAdvisor;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowMailboxDetailController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetMailboxDetail $getMailboxDetail,
        private readonly MailboxConnectionRepository $mailboxConnectionRepository,
        private readonly MailboxHealthAdvisor $mailboxHealthAdvisor,
        private readonly RuaScenarioResolver $ruaScenarioResolver,
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

        // Read-side query already guarded cross-tenant access — safe to load
        // the write-side entity now for the advisor (needs createdAt and the
        // optional monitoredDomain link, neither of which the read DTO carries).
        $entity = $this->mailboxConnectionRepository->get(Uuid::fromString($id));
        $activitySummary = $this->getMailboxDetail->summaryForMailboxes([$id])[$id]
            ?? MailboxActivitySummary::empty();

        $ruaScenario = null !== $entity->monitoredDomain
            ? $this->ruaScenarioResolver->resolveForDomain($entity->monitoredDomain)
            : null;

        $advisorResult = $this->mailboxHealthAdvisor->advise($entity, $activitySummary, $ruaScenario);

        return $this->render('dashboard/mailbox_detail.html.twig', [
            'mailbox' => $mailbox,
            'recentEnvelopes' => $recentEnvelopes,
            'advisorResult' => $advisorResult,
        ]);
    }
}
