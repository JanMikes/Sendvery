<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDnsHealthOverview;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DnsHealthOverviewController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDnsHealthOverview $getDnsHealthOverview,
    ) {
    }

    #[Route('/app/dns-health', name: 'dashboard_dns_health')]
    public function __invoke(): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $domains = $this->getDnsHealthOverview->forTeams($teamIds);

        return $this->render('dashboard/dns_health_overview.html.twig', [
            'domains' => $domains,
        ]);
    }
}
