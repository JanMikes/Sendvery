<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetAlerts;
use App\Query\GetAllReports;
use App\Query\GetDashboardStats;
use App\Query\GetDomainOverview;
use App\Query\GetDomainPassRateTrend;
use App\Query\GetDomainVerificationStatus;
use App\Query\GetMonthlyReportUsage;
use App\Query\GetTeamPlan;
use App\Repository\MailboxConnectionRepository;
use App\Repository\QuarantinedDmarcReportRepository;
use App\Results\MonthlyReportUsageResult;
use App\Services\DashboardContext;
use App\Services\DomainVerificationEvaluator;
use App\Services\HealthSummaryResolver;
use App\Services\NextActionResolver;
use App\Services\ReportAddressProvider;
use App\Services\Stripe\PlanLimits;
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
        private readonly GetDomainVerificationStatus $verificationStatusQuery,
        private readonly DomainVerificationEvaluator $verificationEvaluator,
        private readonly ReportAddressProvider $reportAddressProvider,
        private readonly QuarantinedDmarcReportRepository $quarantineRepository,
        private readonly NextActionResolver $nextActionResolver,
        private readonly HealthSummaryResolver $healthSummaryResolver,
        private readonly MailboxConnectionRepository $mailboxRepository,
        private readonly GetMonthlyReportUsage $getMonthlyReportUsage,
        private readonly GetTeamPlan $getTeamPlan,
        private readonly PlanLimits $planLimits,
    ) {
    }

    #[Route('/app', name: 'dashboard_overview')]
    public function __invoke(): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();

        $stats = $this->getDashboardStats->forTeams($teamIds);
        $domains = $this->getDomainOverview->forTeams($teamIds);
        $recentReports = $this->getAllReports->forTeams($teamIds, limit: 10);
        $trendData = $this->getDomainPassRateTrend->forTeams($teamIds, days: 30);

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

        $unreadAlertCount = $this->getAlerts->countUnreadForTeams($teamIds);
        $recentAlerts = $this->getAlerts->forTeams(
            teamIds: $teamIds,
            severity: 'critical',
            limit: 5,
        );

        $verificationStatus = $this->verificationStatusQuery->forTeams($teamIds);
        $verificationSeverity = null === $verificationStatus
            ? null
            : $this->verificationEvaluator->severity($verificationStatus);

        // Surface the quarantine count for the team's headline domain when it's
        // still unverified — reports already arriving for them is a strong
        // "finish DNS setup now" hook, not just abstract "reports might arrive".
        $quarantineCount = 0;
        if (null !== $verificationStatus && null === $verificationStatus->dmarcVerifiedAt) {
            $quarantineCount = $this->quarantineRepository->countForDomain($verificationStatus->domainName);
        }

        $unreadCriticalAlertCount = $this->getAlerts->countUnreadCriticalForTeams($teamIds);
        $hasMailbox = [] !== $this->mailboxRepository->findByTeam($this->dashboardContext->getTeamId());

        $nextAction = $this->nextActionResolver->resolve(
            domains: $domains,
            verificationStatus: $verificationStatus,
            verificationSeverity: $verificationSeverity,
            unreadCriticalAlertCount: $unreadCriticalAlertCount,
            quarantineCount: $quarantineCount,
            hasMailbox: $hasMailbox,
        );

        $healthSummary = $this->healthSummaryResolver->resolve(
            domains: $domains,
            verificationStatus: $verificationStatus,
            verificationSeverity: $verificationSeverity,
        );

        // Monthly-reports surface: a 6th stat card, but only when the team is
        // on a finite-limit plan AND has crossed 50% of its monthly cap.
        // Low-usage teams keep a clean overview free of "0 / 1000" noise.
        $overviewReportUsage = null;
        $showReportUsageCard = false;
        $rawUsage = $this->getMonthlyReportUsage->forTeam($this->dashboardContext->getTeamId()->toString());
        if (null !== $rawUsage) {
            $plan = $this->getTeamPlan->forTeam($this->dashboardContext->getTeamId()->toString());
            $maxReports = $this->planLimits->getMaxReportsPerMonth($plan);
            $isUnlimited = PHP_INT_MAX === $maxReports;
            $percentageUsed = $isUnlimited || 0 === $maxReports
                ? 0.0
                : min(100.0, ($rawUsage->currentCount / $maxReports) * 100.0);
            $overviewReportUsage = new MonthlyReportUsageResult(
                currentCount: $rawUsage->currentCount,
                limit: $maxReports,
                percentageUsed: $percentageUsed,
                periodEndsAt: $rawUsage->periodEndsAt,
                planOverageQuarantineCount: $rawUsage->planOverageQuarantineCount,
                isUnlimited: $isUnlimited,
                retentionDays: $this->planLimits->getRetentionDays($plan),
            );
            $showReportUsageCard = !$isUnlimited && $overviewReportUsage->percentageUsed >= 50.0;
        }

        return $this->render('dashboard/overview.html.twig', [
            'stats' => $stats,
            'domains' => $domains,
            'recentReports' => $recentReports,
            'trendChartConfig' => $trendChartConfig,
            'unreadAlertCount' => $unreadAlertCount,
            'recentAlerts' => $recentAlerts,
            'verificationStatus' => $verificationStatus,
            'verificationSeverity' => $verificationSeverity,
            'reportAddress' => $this->reportAddressProvider->get(),
            'quarantineCount' => $quarantineCount,
            'nextAction' => $nextAction,
            'healthSummary' => $healthSummary,
            'overviewReportUsage' => $overviewReportUsage,
            'showReportUsageCard' => $showReportUsageCard,
        ]);
    }
}
