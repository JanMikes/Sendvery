<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainDetail;
use App\Query\GetDomainPassRateTrend;
use App\Query\GetDomainReports;
use App\Query\GetDomainSenderBreakdown;
use App\Repository\QuarantinedDmarcReportRepository;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowDomainDetailController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetDomainReports $getDomainReports,
        private readonly GetDomainSenderBreakdown $getDomainSenderBreakdown,
        private readonly GetDomainPassRateTrend $getDomainPassRateTrend,
        private readonly QuarantinedDmarcReportRepository $quarantineRepository,
    ) {
    }

    #[Route('/app/domains/{id}', name: 'dashboard_domain_detail')]
    public function __invoke(string $id): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $domain = $this->getDomainDetail->forDomain($id, $teamIds);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $reports = $this->getDomainReports->forDomain($id, $teamIds, limit: 10);
        $senders = $this->getDomainSenderBreakdown->forDomain($id, $teamIds);
        $trendData = $this->getDomainPassRateTrend->forDomain($id, $teamIds, days: 90);

        $trendChartConfig = [
            'chart' => ['type' => 'area', 'height' => 280],
            'series' => [
                ['name' => 'Pass', 'data' => array_map(static fn ($t) => $t->passCount, $trendData)],
                ['name' => 'Fail', 'data' => array_map(static fn ($t) => $t->failCount, $trendData)],
            ],
            'xaxis' => [
                'categories' => array_map(static fn ($t) => $t->date, $trendData),
                'type' => 'datetime',
            ],
            'colors' => ['#34d399', '#f87171'],
            'stroke' => ['curve' => 'smooth', 'width' => 2],
            'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.4, 'opacityTo' => 0.05]],
            'dataLabels' => ['enabled' => false],
            'tooltip' => ['x' => ['format' => 'MMM dd']],
        ];

        $senderLabels = array_map(static fn ($s) => $s->resolvedOrg ?? $s->sourceIp, $senders);
        $senderChartConfig = [
            'chart' => ['type' => 'bar', 'height' => 300],
            'series' => [
                ['name' => 'Pass', 'data' => array_map(static fn ($s) => $s->passCount, $senders)],
                ['name' => 'Fail', 'data' => array_map(static fn ($s) => $s->failCount, $senders)],
            ],
            'xaxis' => ['categories' => $senderLabels],
            'colors' => ['#34d399', '#f87171'],
            'plotOptions' => ['bar' => ['horizontal' => true, 'barHeight' => '70%', 'stacked' => true]],
            'dataLabels' => ['enabled' => false],
        ];

        // Show "N reports waiting" only while the domain isn't verified yet —
        // once verified, the quarantine release moves them into the dashboard
        // proper so a count would be stale.
        $quarantineCount = $domain->isVerified()
            ? 0
            : $this->quarantineRepository->countForDomain($domain->domainName);

        return $this->render('dashboard/domain_detail.html.twig', [
            'domain' => $domain,
            'reports' => $reports,
            'senders' => $senders,
            'trendChartConfig' => $trendChartConfig,
            'senderChartConfig' => $senderChartConfig,
            'quarantineCount' => $quarantineCount,
        ]);
    }
}
