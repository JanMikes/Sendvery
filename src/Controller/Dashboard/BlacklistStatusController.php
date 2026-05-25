<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetBlacklistStatus;
use App\Query\GetDomainDetail;
use App\Query\GetDomainWorkspaceTabCounts;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlacklistStatusController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetBlacklistStatus $getBlacklistStatus,
        private readonly GetDomainWorkspaceTabCounts $getDomainWorkspaceTabCounts,
    ) {
    }

    #[Route('/app/domains/{id}/blacklist', name: 'dashboard_blacklist_status')]
    public function __invoke(string $id): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $domain = $this->getDomainDetail->forDomain($id, $teamIds);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $statusResults = $this->getBlacklistStatus->forDomain($id, $teamIds);

        $tabCounts = $this->getDomainWorkspaceTabCounts->forDomain($id)->toTwigArray();

        return $this->render('dashboard/blacklist_status.html.twig', [
            'domain' => $domain,
            'statusResults' => $statusResults,
            'tabCounts' => $tabCounts,
        ]);
    }
}
