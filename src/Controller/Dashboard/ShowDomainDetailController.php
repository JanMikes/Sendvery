<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetAllReports;
use App\Query\GetDnsHealthOverview;
use App\Query\GetDomainDetail;
use App\Query\GetDomainPassRateTrend;
use App\Query\GetTopSendersForDomain;
use App\Repository\QuarantinedDmarcReportRepository;
use App\Services\DashboardContext;
use App\Services\DmarcPolicyAdvisor;
use App\Services\Dns\RuaScenarioResolver;
use App\Services\DomainSetupStatusResolver;
use App\Value\DmarcPolicy;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShowDomainDetailController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetAllReports $getAllReports,
        private readonly GetTopSendersForDomain $getTopSendersForDomain,
        private readonly GetDomainPassRateTrend $getDomainPassRateTrend,
        private readonly QuarantinedDmarcReportRepository $quarantineRepository,
        private readonly GetDnsHealthOverview $getDnsHealthOverview,
        private readonly DmarcPolicyAdvisor $dmarcPolicyAdvisor,
        private readonly DomainSetupStatusResolver $domainSetupStatusResolver,
        private readonly RuaScenarioResolver $ruaScenarioResolver,
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

        $reports = $this->getAllReports->forTeams($teamIds, limit: 10, domainId: $id);
        $senders = $this->getTopSendersForDomain->forDomain($id, $teamIds, limit: 5);
        $senderSummary = $this->getTopSendersForDomain->summaryForDomain($id, $teamIds);
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

        // Colour each bar by authorization status: --color-success for
        // authorised IPs (we know who you are, no action needed) and
        // --color-warning for unknown ones (you should review these). Using
        // CSS variables instead of hex literals keeps the chart palette in
        // sync with the theme tokens and lets the rendered config self-document
        // the colour rule for the test suite (TASK-038).
        $senderLabels = array_map(static fn ($s) => $s->displayLabel, $senders);
        $senderColors = array_map(
            static fn ($s) => true === $s->senderIsAuthorized
                ? 'var(--color-success)'
                : 'var(--color-warning)',
            $senders,
        );
        $senderChartConfig = [
            'chart' => ['type' => 'bar', 'height' => 300],
            'series' => [
                ['name' => 'Messages', 'data' => array_map(static fn ($s) => $s->totalMessages, $senders)],
            ],
            'xaxis' => ['categories' => $senderLabels],
            'colors' => $senderColors,
            'plotOptions' => ['bar' => ['horizontal' => true, 'barHeight' => '70%', 'distributed' => true]],
            'legend' => ['show' => false],
            'dataLabels' => ['enabled' => false],
        ];

        // Show "N reports waiting" only while the domain isn't verified yet —
        // once verified, the quarantine release moves them into the dashboard
        // proper so a count would be stale.
        $quarantineCount = $domain->isVerified()
            ? 0
            : $this->quarantineRepository->countForDomain($domain->domainName);

        $dnsHealth = $this->getDnsHealthOverview->forDomain($id, $teamIds);
        $ruaScenario = $this->ruaScenarioResolver->resolveForDomainId(Uuid::fromString($id));
        $domainSetupStatus = $this->domainSetupStatusResolver->resolve($dnsHealth, $ruaScenario);

        // The detail result carries `dmarc_policy` as a raw nullable string
        // straight from DBAL — `tryFrom` (not `from`) protects against a DB
        // value the enum doesn't yet recognise (legacy rows, future spec
        // revisions). Null and unknown both collapse to `p=none` so the
        // advisor's empty-state branch handles the rest.
        //
        // TASK-099: the source-of-truth boolean for "is there a DMARC TXT
        // record published?" is the raw column on MonitoredDomain, NOT this
        // coerced fallback. Without it the DmarcPolicyExplainer would lie to
        // first-touch users ("DMARC reports are being collected") for a
        // domain that hasn't published any DMARC record at all — the
        // explainer is hidden in the template when this is false.
        $hasPublishedDmarcRecord = null !== $domain->dmarcPolicy;
        $currentPolicy = null === $domain->dmarcPolicy
            ? DmarcPolicy::None
            : (DmarcPolicy::tryFrom($domain->dmarcPolicy) ?? DmarcPolicy::None);
        // Both inputs to the advisor MUST measure the same trailing window —
        // the lifetime pass rate on $domain mixes old and recent posture and
        // would tell a recovering domain it's not ready when it is, or the
        // reverse for a degrading one.
        $recentActivity = $this->getDomainDetail->getRecentActivity($id, $teamIds);
        $dmarcPolicyAdvice = $this->dmarcPolicyAdvisor->adviseFor(
            $currentPolicy,
            $recentActivity->passRate,
            $recentActivity->reportsCount,
        );

        return $this->render('dashboard/domain_detail.html.twig', [
            'domain' => $domain,
            'reports' => $reports,
            'senders' => $senders,
            'senderSummary' => $senderSummary,
            'trendChartConfig' => $trendChartConfig,
            'senderChartConfig' => $senderChartConfig,
            'quarantineCount' => $quarantineCount,
            'dnsHealth' => $dnsHealth,
            'dmarcPolicyAdvice' => $dmarcPolicyAdvice,
            'domainSetupStatus' => $domainSetupStatus,
            'hasPublishedDmarcRecord' => $hasPublishedDmarcRecord,
        ]);
    }
}
