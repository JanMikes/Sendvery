<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetAllReports;
use App\Query\GetDomainDetail;
use App\Query\GetDomainWorkspaceTabCounts;
use App\Query\GetReporterOrgs;
use App\Services\DashboardContext;
use App\Value\ReportsFilter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListDomainReportsController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetAllReports $getAllReports,
        private readonly GetReporterOrgs $getReporterOrgs,
        private readonly GetDomainDetail $getDomainDetail,
        private readonly ClockInterface $clock,
        private readonly GetDomainWorkspaceTabCounts $getDomainWorkspaceTabCounts,
    ) {
    }

    #[Route('/app/domains/{id}/reports', name: 'dashboard_domain_reports')]
    public function __invoke(Request $request, string $id): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $domain = $this->getDomainDetail->forDomain($id, $teamIds);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $filter = ReportsFilter::fromRequest($request, $this->clock);

        $reports = $this->getAllReports->forTeams(
            $teamIds,
            limit: $limit,
            offset: $offset,
            domainId: $id,
            reporterOrgs: [] !== $filter->reporterOrgs ? $filter->reporterOrgs : null,
            passRateBand: $filter->passRateBand,
            dateFrom: $filter->dateFrom,
            dateTo: $filter->dateTo,
            search: $filter->search,
            mailboxId: $filter->mailboxId,
        );

        $reporterOptions = $this->getReporterOrgs->forTeams($teamIds);

        $template = $request->headers->has('Turbo-Frame')
            ? 'dashboard/_domain_reports_table.html.twig'
            : 'dashboard/domain_reports.html.twig';

        $tabCounts = $this->getDomainWorkspaceTabCounts->forDomain($id)->toTwigArray();

        return $this->render($template, [
            'reports' => $reports,
            'domain' => $domain,
            'domainId' => $id,
            'currentPage' => $page,
            'hasNextPage' => count($reports) === $limit,
            'filter' => $filter,
            'reporterOptions' => $reporterOptions,
            'filterParams' => $filter->toQueryParams(),
            'tabCounts' => $tabCounts,
        ]);
    }
}
