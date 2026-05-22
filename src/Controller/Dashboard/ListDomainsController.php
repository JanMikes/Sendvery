<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainOverview;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function __invoke(): Response
    {
        $domains = $this->getDomainOverview->forTeams($this->dashboardContext->getTeamIdStrings());

        return $this->render('dashboard/domains.html.twig', [
            'domains' => $domains,
            // Show the Team column only when the user actually belongs to
            // more than one team — single-team users would just see a noisy
            // column repeating the same name on every row.
            'showTeamColumn' => count($this->dashboardContext->getTeamIds()) > 1,
        ]);
    }
}
