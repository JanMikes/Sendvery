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
        $teamId = $this->dashboardContext->getTeamId();
        $domains = $this->getDomainOverview->forTeam($teamId->toString());

        return $this->render('dashboard/domains.html.twig', [
            'domains' => $domains,
        ]);
    }
}
