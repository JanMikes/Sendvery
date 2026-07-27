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
use App\Results\DomainOverviewResult;
use App\Results\MonthlyReportUsageResult;
use App\Services\AttentionSummaryResolver;
use App\Services\DashboardContext;
use App\Services\Dns\RuaScenarioResolver;
use App\Services\DomainAttentionResolver;
use App\Services\DomainVerificationEvaluator;
use App\Services\HealthSummaryResolver;
use App\Services\IngestionPathResolver;
use App\Services\NextActionResolver;
use App\Services\ReportAddressProvider;
use App\Services\SetupChecklistResolver;
use App\Services\Stripe\PlanLimits;
use App\Value\DomainHealthSort;
use App\Value\SetupChecklistDomain;
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
        private readonly RuaScenarioResolver $ruaScenarioResolver,
        private readonly AttentionSummaryResolver $attentionSummaryResolver,
        private readonly DomainAttentionResolver $domainAttentionResolver,
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
        // One line, same reason as `$focusDomain` below: the null-headline-domain
        // arm is unreachable from `/app` because the onboarding redirect fires
        // first, so a multi-line ternary parks a line coverage can never reach.
        $verificationSeverity = null === $verificationStatus ? null : $this->verificationEvaluator->severity($verificationStatus);

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

        // TASK-100: resolve the RUA scenario for the headline domain so the
        // NextActionResolver can switch its recommendation based on whether
        // DMARC already routes reports to Sendvery (scenario b), to an
        // external inbox the user owns (scenario c), or nowhere (scenario a).
        // `$verificationStatus` is the single-domain headline used elsewhere
        // on this page, so resolving for its `domainId` keeps the surface
        // consistent.
        //
        // Per-domain RUA scenarios for NextActionResolver. TASK-134: one batch
        // query rather than the per-domain foreach the controller used to run
        // — the previous shape went N+1 on the dashboard overview hot path
        // (~3.5ms at 20 domains, projected ~500ms at 500). Keyed by domainId
        // so the resolver can pair each `DomainOverviewResult` with its
        // scenario without re-iterating.
        $domainRuaScenarios = $this->ruaScenarioResolver->resolveForDomainIds(
            array_values(array_map(static fn ($d) => $d->domainId, $domains)),
        );

        // TASK-129: the headline scenario is the LIMIT-1 verification-status
        // domain's entry in the batch map — SetupChecklistResolver below still
        // consumes the LIMIT-1 headline value, but we read it from the batch
        // result instead of issuing a redundant per-domain query (TASK-134
        // reviewer catch — the headline domain was being fetched twice).
        $headlineDomainRuaScenario = null === $verificationStatus ? null : ($domainRuaScenarios[$verificationStatus->domainId] ?? null);

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
            headlineDomainRuaScenario: $headlineDomainRuaScenario,
            domainRuaScenarios: $domainRuaScenarios,
        );

        $healthSummary = $this->healthSummaryResolver->resolve(
            domains: $domains,
            verificationStatus: $verificationStatus,
            verificationSeverity: $verificationSeverity,
        );

        // Chip row inside the focus card. `$domains` is handed in so the domain
        // counts here are classified from the very same rows HealthSummaryResolver
        // classified two lines up — that is what stops the headline ("3 domains
        // need attention") and the chips ("1 unverified domain") from telling two
        // different stories, which is exactly what they used to do.
        $attentionSummary = $this->attentionSummaryResolver->resolveForTeam(
            $this->dashboardContext->getTeamId()->toString(),
            $domains,
        );

        // The "which domains need attention and why" list. Reads the per-domain
        // setup status for the handful of rows it renders, so each reason and
        // each deep link is the same one that domain's own page shows.
        $domainAttention = $this->domainAttentionResolver->resolve(
            domains: $domains,
            teamIds: $teamIds,
            verificationStatus: $verificationStatus,
            verificationSeverity: $verificationSeverity,
            quarantineCount: $quarantineCount,
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
        // TASK-128: pass the headline domain's RUA scenario into the resolver
        // so the "Receive your first DMARC report" step swaps its copy/CTA to
        // match the user's actual published state — telling a PointsAtSendvery
        // user they could "alternatively connect a mailbox" contradicts the
        // correctly-configured DNS they just set up. The headline scenario is
        // already computed above for NextActionResolver; we reuse it here so a
        // future refactor that changes how the headline domain is picked stays
        // consistent across both surfaces.
        // NOTE for TASK-129: this call site shares `$headlineDomainRuaScenario`
        // with NextActionResolver above. If TASK-129 refactors how that value
        // is computed, keep BOTH consumers in sync — they intentionally read
        // the same headline-domain scenario.
        // The checklist names ONE domain in its open steps and deep-links that
        // domain's setup surface — "Publish your DMARC record → Do it" was
        // unanswerable for anyone with more than one domain. The named domain is
        // the same headline domain the Next Action card and the RUA scenario
        // above already speak about, deliberately NOT `$domains[0]`: that array
        // is ordered by the ?domain_health_sort= dropdown, so reading position 0
        // would rename the checklist's domain whenever the user re-sorted the
        // Domain Health card below.
        //
        // Written on one line on purpose: a null headline domain means the team
        // has no domains at all, and `OnboardingRedirectListener` intercepts that
        // request long before this controller runs — so the null arm is real but
        // unreachable from `/app`, and splitting it across lines would leave a
        // permanently uncoverable one.
        $focusDomain = null === $verificationStatus ? null : new SetupChecklistDomain($verificationStatus->domainId, $verificationStatus->domainName);
        $setupChecklist = $this->setupChecklistResolver->resolve(
            domainCount: count($domains),
            anyDomainHasDmarcVerified: $anyDomainHasDmarcVerified,
            anyDomainHasFirstReport: $anyDomainHasFirstReport,
            hasMailbox: $hasMailbox,
            dismissedAt: $team->setupChecklistDismissedAt,
            hasDmarcRegression: $hasDmarcRegression,
            headlineDomainRuaScenario: $headlineDomainRuaScenario?->scenario,
            focusDomain: $focusDomain,
            otherUnfinishedDomains: $this->collectOtherUnfinishedDomains($domains, $focusDomain),
        );

        return $this->render('dashboard/overview.html.twig', [
            'stats' => $stats,
            'domains' => $domains,
            'recentReports' => $recentReports,
            'trendChartConfig' => $trendChartConfig,
            'unreadAlertCount' => $unreadAlertCount,
            'recentAlerts' => $recentAlerts,
            'nextAction' => $nextAction,
            'healthSummary' => $healthSummary,
            'attentionSummary' => $attentionSummary,
            'domainAttention' => $domainAttention,
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
     * Domains other than the checklist's focused one that still have no verified
     * DMARC record, so the card can offer them as one-click links instead of
     * pretending the focused domain is the only one left to set up.
     *
     * Capped at three: this is a footnote under a three-step onboarding card, and
     * the attention list right below it is the surface built to enumerate every
     * affected domain.
     *
     * @param array<DomainOverviewResult> $domains
     *
     * @return list<SetupChecklistDomain>
     */
    private function collectOtherUnfinishedDomains(array $domains, ?SetupChecklistDomain $focusDomain): array
    {
        $others = [];
        foreach ($domains as $domain) {
            if (null !== $domain->dmarcVerifiedAt) {
                continue;
            }

            if (null !== $focusDomain && $domain->domainId === $focusDomain->id) {
                continue;
            }

            $others[] = new SetupChecklistDomain($domain->domainId, $domain->domainName);
        }

        // Alphabetical, not the incoming order: `$domains` is sorted by the
        // ?domain_health_sort= dropdown, and this footnote must not reshuffle
        // when the user re-sorts an unrelated card.
        usort($others, static fn (SetupChecklistDomain $a, SetupChecklistDomain $b): int => strcmp($a->name, $b->name));

        return array_slice($others, 0, 3);
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
