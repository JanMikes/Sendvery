<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetAllReports;
use App\Query\GetDomainOverview;
use App\Query\GetReporterOrgs;
use App\Query\GetTeamPassRateAggregates;
use App\Query\GetTopFailingSenderForTeam;
use App\Services\DashboardContext;
use App\Services\PassRateRegressionAdvisor;
use App\Value\ReportsFilter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListReportsController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetAllReports $getAllReports,
        private readonly GetReporterOrgs $getReporterOrgs,
        private readonly GetDomainOverview $getDomainOverview,
        private readonly GetTeamPassRateAggregates $getTeamPassRateAggregates,
        private readonly GetTopFailingSenderForTeam $getTopFailingSenderForTeam,
        private readonly PassRateRegressionAdvisor $passRateRegressionAdvisor,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/app/reports', name: 'dashboard_reports')]
    public function __invoke(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $filter = ReportsFilter::fromRequest($request, $this->clock);

        $reports = $this->getAllReports->forTeams(
            $teamIds,
            limit: $limit,
            offset: $offset,
            domainIds: [] !== $filter->domainIds ? $filter->domainIds : null,
            reporterOrgs: [] !== $filter->reporterOrgs ? $filter->reporterOrgs : null,
            passRateBand: $filter->passRateBand,
            dateFrom: $filter->dateFrom,
            dateTo: $filter->dateTo,
            search: $filter->search,
            mailboxId: $filter->mailboxId,
        );

        $domains = $this->getDomainOverview->forTeams($teamIds);
        $reporterOptions = $this->getReporterOrgs->forTeams($teamIds);

        $regressionResult = null;
        if (!$request->headers->has('Turbo-Frame')) {
            $aggregates = $this->getTeamPassRateAggregates->forTeams($teamIds);
            $topFailing = $this->getTopFailingSenderForTeam->forTeams($teamIds);
            $regressionResult = $this->passRateRegressionAdvisor->advise(
                window7d: $aggregates['window7d'],
                baseline30d: $aggregates['baseline30d'],
                topFailingSender: $topFailing,
            );
        }

        $template = $request->headers->has('Turbo-Frame')
            ? 'dashboard/_reports_table.html.twig'
            : 'dashboard/reports.html.twig';

        return $this->render($template, [
            'reports' => $reports,
            'currentPage' => $page,
            'hasNextPage' => count($reports) === $limit,
            'filter' => $filter,
            'domains' => $domains,
            'reporterOptions' => $reporterOptions,
            'filterParams' => $filter->toQueryParams(),
            'regressionResult' => $regressionResult,
        ]);
    }
}
