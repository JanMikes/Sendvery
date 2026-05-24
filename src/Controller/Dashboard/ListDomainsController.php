<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainOverview;
use App\Services\DashboardContext;
use App\Services\DomainHealthClassifier;
use App\Value\DomainHealthFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListDomainsController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainOverview $getDomainOverview,
        private readonly DomainHealthClassifier $domainHealthClassifier,
    ) {
    }

    #[Route('/app/domains', name: 'dashboard_domains')]
    public function __invoke(Request $request): Response
    {
        $teamIdStrings = $this->dashboardContext->getTeamIdStrings();
        $statusFilter = DomainHealthFilter::tryFrom($request->query->getString('status', ''));
        $domains = $this->getDomainOverview->forTeams($teamIdStrings, $statusFilter);
        $totalDomainCount = $this->getDomainOverview->countForTeams($teamIdStrings);

        // TASK-098: severity per card now comes from the unified
        // `DomainHealthClassifier` (same service the detail-page banner uses).
        // Pre-compute as a domain-id → severity map so the template stays
        // logic-free — Twig doesn't speak service injection per-row, and we
        // want a single instantiation point rather than one classifier call
        // buried inside a component prop.
        $severityByDomain = [];
        foreach ($domains as $domain) {
            $severityByDomain[$domain->domainId] = $this->domainHealthClassifier->classifyOverview($domain);
        }

        return $this->render('dashboard/domains.html.twig', [
            'domains' => $domains,
            // Show the Team column only when the user actually belongs to
            // more than one team — single-team users would just see a noisy
            // column repeating the same name on every row.
            'showTeamColumn' => count($this->dashboardContext->getTeamIds()) > 1,
            'activeFilter' => $statusFilter,
            'totalDomainCount' => $totalDomainCount,
            'severityByDomain' => $severityByDomain,
        ]);
    }
}
