<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetAllReports;
use App\Query\GetQuarantineList;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListQuarantineController extends AbstractController
{
    private const int PAGE_SIZE = 50;

    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetQuarantineList $getQuarantineList,
        private readonly GetAllReports $getAllReports,
    ) {
    }

    #[Route('/app/quarantine', name: 'dashboard_quarantine', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $teamId = $this->dashboardContext->getTeamId();
        $items = $this->getQuarantineList->forTeam(
            $teamId->toString(),
            limit: self::PAGE_SIZE,
            offset: $offset,
        );

        $mostRecentReportId = null;
        if ([] === $items && 1 === $page) {
            // The "view most recent report" empty-state CTA only makes sense
            // when the user actually has a report to look at — otherwise it
            // links into a void.
            $recent = $this->getAllReports->forTeams(
                $this->dashboardContext->getTeamIdStrings(),
                limit: 1,
            );
            if ([] !== $recent) {
                $mostRecentReportId = $recent[0]->reportId;
            }
        }

        return $this->render('dashboard/quarantine.html.twig', [
            'items' => $items,
            'currentPage' => $page,
            'hasNextPage' => self::PAGE_SIZE === count($items),
            'mostRecentReportId' => $mostRecentReportId,
        ]);
    }
}
