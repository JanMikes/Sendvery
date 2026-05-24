<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetAlerts;
use App\Query\GetAllReports;
use App\Query\GetDashboardStats;
use App\Query\GetDomainOverview;
use App\Query\GetDomainPassRateTrend;
use App\Query\GetDomainVerificationStatus;
use App\Query\GetEarliestDomainAddedAt;
use App\Query\GetMonthlyReportUsage;
use App\Query\GetTeamPlan;
use App\Repository\MailboxConnectionRepository;
use App\Repository\QuarantinedDmarcReportRepository;
use App\Repository\TeamRepository;
use App\Results\MonthlyReportUsageResult;
use App\Services\DashboardContext;
use App\Services\DomainVerificationEvaluator;
use App\Services\HealthSummaryResolver;
use App\Services\IngestionPathResolver;
use App\Services\NextActionResolver;
use App\Services\ReportAddressProvider;
use App\Services\SetupChecklistResolver;
use App\Services\Stripe\PlanLimits;
use App\Value\DomainHealthSort;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly SetupChecklistResolver $setupChecklistResolver,
        private readonly TeamRepository $teamRepository,
        private readonly ClockInterface $clock,
        private readonly IngestionPathResolver $ingestionPathResolver,
        private readonly GetEarliestDomainAddedAt $getEarliestDomainAddedAt,
    ) {
    }

    #[Route('/app', name: 'dashboard_overview')]
    public function __invoke(Request $request): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();

        // TASK-040: in-card filter URL state. Each accessor pins to a safe
        // default when the param is missing or unrecognised, so the page
        // always renders with sensible content even on garbage input.
        $recentReportsRange = $this->parseRecentReportsRange($request);
        $recentReportsFailing = $this->parseRecentReportsFailing($request);
        $domainHealthSort = $this->parseDomainHealthSort($request);

        // ?recent_reports_range= folds onto ReportsFilter::dateFrom — we
        // compute the cutoff once here so the same value drives the query
        // AND the dropdown's "active" highlight in the template.
        $rangeDays = (int) substr($recentReportsRange, 0, -1);
        $recentReportsDateFrom = $this->clock->now()->modify(sprintf('-%d days', $rangeDays));

        $stats = $this->getDashboardStats->forTeams($teamIds);
        $domains = $this->getDomainOverview->forTeams($teamIds, sort: $domainHealthSort);
        $recentReports = $this->getAllReports->forTeams(
            teamIds: $teamIds,
            limit: 10,
            passRateBand: $recentReportsFailing ? 'low' : null,
            dateFrom: $recentReportsDateFrom,
        );
        $trendData = $this->getDomainPassRateTrend->forTeams($teamIds, days: 30);

        // Per-domain 30-day sparkline data for the Domain Health card. We only
        // need it for the 5 domains the template renders, so trim before the
        // query to avoid pulling ~30 rows per unrendered domain on accounts
        // with lots of monitored domains.
        $domainSparklineIds = array_values(array_map(
            static fn ($d) => $d->domainId,
            array_slice($domains, 0, 5),
        ));
        // `forDomains` short-circuits to `[]` when either input is empty, so
        // we don't need a defensive guard here — the query is safe to call
        // with an empty list and the overview always passes the team scope.
        $domainPassRateTrends = $this->getDomainPassRateTrend->forDomains($domainSparklineIds, $teamIds, days: 30);

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

        // TASK-091 inputs — DNS-first next-step. The resolver needs to know
        // (a) whether the central inbox is already delivering reports, (b)
        // how old the oldest domain is (for the 7-day fallback timer), and
        // (c) whether the team has explicitly dismissed the recommendation.
        // $team is loaded here (was previously created for the setup-checklist
        // branch further down) so we can read `ingestionRecommendationDismissedAt`.
        $team = $this->teamRepository->get($this->dashboardContext->getTeamId());
        $ingestionPaths = $this->ingestionPathResolver->resolveForTeams($teamIds);
        $earliestDomainAddedAt = $this->getEarliestDomainAddedAt->forTeams($teamIds);
        $reportAddress = $this->reportAddressProvider->get();

        $nextAction = $this->nextActionResolver->resolve(
            domains: $domains,
            verificationStatus: $verificationStatus,
            verificationSeverity: $verificationSeverity,
            unreadCriticalAlertCount: $unreadCriticalAlertCount,
            quarantineCount: $quarantineCount,
            hasMailbox: $hasMailbox,
            reportAddress: $reportAddress,
            earliestDomainAddedAt: $earliestDomainAddedAt,
            ingestionPaths: $ingestionPaths,
            ingestionRecommendationDismissedAt: $team->ingestionRecommendationDismissedAt,
            now: $this->clock->now(),
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

        // Onboarding setup checklist — persistent, dismissible, auto-re-shown
        // on DMARC regression. Inputs come from already-fetched team state so
        // we don't add any extra DB round-trips on the overview hot path.
        // Note: $verificationStatus reflects only the most-recently-added
        // domain (LIMIT 1 in GetDomainVerificationStatus), so the DMARC /
        // first-report signals here are single-domain — same as the Next
        // Action card. A multi-domain "any" check is a v2 enhancement.
        $hasDmarcRegression = null !== $verificationStatus
            && null !== $verificationStatus->dmarcVerifiedAt
            && $verificationStatus->consecutiveDmarcFailures >= 2;
        $anyDomainHasDmarcVerified = null !== $verificationStatus
            && null !== $verificationStatus->dmarcVerifiedAt;
        $anyDomainHasFirstReport = null !== $verificationStatus
            && null !== $verificationStatus->firstReportAt;
        $setupChecklist = $this->setupChecklistResolver->resolve(
            domainCount: count($domains),
            anyDomainHasDmarcVerified: $anyDomainHasDmarcVerified,
            anyDomainHasFirstReport: $anyDomainHasFirstReport,
            hasMailbox: $hasMailbox,
            dismissedAt: $team->setupChecklistDismissedAt,
            hasDmarcRegression: $hasDmarcRegression,
        );

        return $this->render('dashboard/overview.html.twig', [
            'stats' => $stats,
            'domains' => $domains,
            'recentReports' => $recentReports,
            'trendChartConfig' => $trendChartConfig,
            'unreadAlertCount' => $unreadAlertCount,
            'recentAlerts' => $recentAlerts,
            'verificationStatus' => $verificationStatus,
            'verificationSeverity' => $verificationSeverity,
            'reportAddress' => $reportAddress,
            'quarantineCount' => $quarantineCount,
            'nextAction' => $nextAction,
            'healthSummary' => $healthSummary,
            'overviewReportUsage' => $overviewReportUsage,
            'showReportUsageCard' => $showReportUsageCard,
            'setupChecklist' => $setupChecklist,
            'recentReportsRange' => $recentReportsRange,
            'recentReportsFailing' => $recentReportsFailing,
            'domainHealthSort' => $domainHealthSort,
            'domainPassRateTrends' => $domainPassRateTrends,
        ]);
    }

    /**
     * @return '7d'|'30d'|'90d'
     */
    private function parseRecentReportsRange(Request $request): string
    {
        $raw = $request->query->get('recent_reports_range');
        if (in_array($raw, ['7d', '30d', '90d'], true)) {
            return $raw;
        }

        return '7d';
    }

    private function parseRecentReportsFailing(Request $request): bool
    {
        return '1' === $request->query->get('recent_reports_failing');
    }

    /**
     * Defaults to Worst — the card's whole purpose is surfacing problems.
     * Garbage / missing → Worst (not null), so the controller and the
     * dropdown's "active" highlight stay in lockstep.
     */
    private function parseDomainHealthSort(Request $request): DomainHealthSort
    {
        $raw = $request->query->get('domain_health_sort');
        if (!is_string($raw)) {
            return DomainHealthSort::Worst;
        }

        return DomainHealthSort::tryFrom($raw) ?? DomainHealthSort::Worst;
    }
}
