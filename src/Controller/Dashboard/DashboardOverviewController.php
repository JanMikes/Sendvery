<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetAlerts;
use App\Query\GetAllReports;
use App\Query\GetDashboardStats;
use App\Query\GetDomainOverview;
use App\Query\GetDomainPassRateTrend;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardOverviewController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDashboardStats $getDashboardStats,
        private readonly GetDomainOverview $getDomainOverview,
        private readonly GetAllReports $getAllReports,
        private readonly GetDomainPassRateTrend $getDomainPassRateTrend,
        private readonly GetAlerts $getAlerts,
    ) {
    }

    #[Route('/app', name: 'dashboard_overview')]
    public function __invoke(): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $teamIdString = $teamId->toString();

        $stats = $this->getDashboardStats->forTeam($teamIdString);
        $domains = $this->getDomainOverview->forTeam($teamIdString);
        $recentReports = $this->getAllReports->forTeam($teamIdString, limit: 10);
        $trendData = $this->getDomainPassRateTrend->forTeam($teamIdString, days: 30);

        $trendChartConfig = [
            'chart' => [
                'type' => 'area',
                'height' => 300,
                'stacked' => false,
            ],
            'series' => [
                [
                    'name' => 'Pass',
                    'data' => array_map(static fn ($t) => $t->passCount, $trendData),
                ],
                [
                    'name' => 'Fail',
                    'data' => array_map(static fn ($t) => $t->failCount, $trendData),
                ],
            ],
            'xaxis' => [
                'categories' => array_map(static fn ($t) => $t->date, $trendData),
                'type' => 'datetime',
            ],
            'colors' => ['#34d399', '#f87171'],
            'stroke' => ['curve' => 'smooth', 'width' => 2],
            'fill' => [
                'type' => 'gradient',
                'gradient' => ['opacityFrom' => 0.4, 'opacityTo' => 0.05],
            ],
            'dataLabels' => ['enabled' => false],
            'tooltip' => ['x' => ['format' => 'MMM dd']],
        ];

        $unreadAlertCount = $this->getAlerts->countUnreadForTeam($teamIdString);
        $recentAlerts = $this->getAlerts->forTeam(
            teamId: $teamIdString,
            severity: 'critical',
            limit: 5,
        );

        return $this->render('dashboard/overview.html.twig', [
            'stats' => $stats,
            'domains' => $domains,
            'recentReports' => $recentReports,
            'trendChartConfig' => $trendChartConfig,
            'unreadAlertCount' => $unreadAlertCount,
            'recentAlerts' => $recentAlerts,
        ]);
    }
}
