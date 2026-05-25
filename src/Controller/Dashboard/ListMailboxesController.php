<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetMailboxDetail;
use App\Repository\MailboxConnectionRepository;
use App\Results\MailboxActivitySummary;
use App\Services\DashboardContext;
use App\Services\IngestionPathResolver;
use App\Services\ReportAddressProvider;
use App\Value\IngestionPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListMailboxesController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MailboxConnectionRepository $mailboxConnectionRepository,
        private readonly GetMailboxDetail $getMailboxDetail,
        private readonly IngestionPathResolver $ingestionPathResolver,
        private readonly ReportAddressProvider $reportAddressProvider,
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

        // Per-domain ingestion classification — drives the matrix table.
        // Scoped across every team the user is a member of so the matrix is
        // consistent with the rest of the dashboard's cross-tenant reads.
        $matrix = $this->ingestionPathResolver->resolveForTeams(
            $this->dashboardContext->getTeamIdStrings(),
        );

        // Single verified-domain teams land directly on that domain's health
        // page; everyone else (including unverified single-domain teams) goes
        // to the DNS overview. Avoids one extra click for the most common
        // shape (one verified domain) without dropping unverified teams onto
        // a per-domain health page that has nothing meaningful to show.
        $dnsCtaUrl = $this->resolveDnsCtaUrl($matrix);

        // TASK-105: collapse the two-card IngestionRoutesCallout to a single
        // confirmation card when every matrix row is already ingesting via
        // Sendvery (scenario b). Empty / brand-new teams stay on the
        // educational two-card layout — handled by the resolver returning
        // false for an empty matrix.
        $allScenarioB = $this->ingestionPathResolver->allScenarioPointsAtSendvery($matrix);

        return $this->render('dashboard/mailboxes.html.twig', [
            'mailboxes' => $mailboxes,
            'activity' => $activity,
            'matrix' => $matrix,
            'reportAddress' => $this->reportAddressProvider->get(),
            'dnsCtaUrl' => $dnsCtaUrl,
            'allScenarioB' => $allScenarioB,
        ]);
    }

    /**
     * @param list<\App\Results\DomainIngestionMatrixResult> $matrix
     */
    private function resolveDnsCtaUrl(array $matrix): string
    {
        // Only deep-link to the per-domain health page when the single domain
        // is already receiving reports (path !== None). An unverified domain
        // would land on an empty per-domain page; the DNS overview is the more
        // useful target since it shows what's missing.
        if (1 === count($matrix) && IngestionPath::None !== $matrix[0]->path) {
            return $this->generateUrl('dashboard_domain_health', ['id' => $matrix[0]->domainId]);
        }

        return $this->generateUrl('dashboard_dns_health');
    }
}
