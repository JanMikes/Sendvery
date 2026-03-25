<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetAllReports;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListReportsController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetAllReports $getAllReports,
    ) {
    }

    #[Route('/app/reports', name: 'dashboard_reports')]
    public function __invoke(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $teamId = $this->dashboardContext->getTeamId();
        $reports = $this->getAllReports->forTeam($teamId->toString(), limit: $limit, offset: $offset);

        $template = $request->headers->has('Turbo-Frame')
            ? 'dashboard/_reports_table.html.twig'
            : 'dashboard/reports.html.twig';

        return $this->render($template, [
            'reports' => $reports,
            'currentPage' => $page,
            'hasNextPage' => count($reports) === $limit,
        ]);
    }
}
