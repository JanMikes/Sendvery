<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainOverview;
use App\Services\DashboardContext;
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
    ) {
    }

    #[Route('/app/domains', name: 'dashboard_domains')]
    public function __invoke(Request $request): Response
    {
        $teamIdStrings = $this->dashboardContext->getTeamIdStrings();
        $statusFilter = DomainHealthFilter::tryFrom($request->query->getString('status', ''));
        $domains = $this->getDomainOverview->forTeams($teamIdStrings, $statusFilter);
        $totalDomainCount = $this->getDomainOverview->countForTeams($teamIdStrings);

        return $this->render('dashboard/domains.html.twig', [
            'domains' => $domains,
            // Show the Team column only when the user actually belongs to
            // more than one team — single-team users would just see a noisy
            // column repeating the same name on every row.
            'showTeamColumn' => count($this->dashboardContext->getTeamIds()) > 1,
            'activeFilter' => $statusFilter,
            'totalDomainCount' => $totalDomainCount,
        ]);
    }
}
