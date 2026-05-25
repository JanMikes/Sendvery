<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDnsHealthOverview;
use App\Results\DnsHealthOverviewResult;
use App\Services\DashboardContext;
use App\Services\DomainHealthClassifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DnsHealthOverviewController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDnsHealthOverview $getDnsHealthOverview,
        private readonly DomainHealthClassifier $classifier,
    ) {
    }

    #[Route('/app/dns-health', name: 'dashboard_dns_health')]
    public function __invoke(Request $request): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $allDomains = $this->getDnsHealthOverview->forTeams($teamIds);

        // TASK-083: 4-card summary counts the full set, BEFORE the ?status=
        // filter trims the list — the badges must always reflect the
        // unfiltered totals so the filter chips link to a known target.
        $totalCount = count($allDomains);
        $healthyCount = 0;
        $attentionCount = 0;
        $awaitingCount = 0;
        foreach ($allDomains as $domain) {
            if (!$domain->hasSnapshot()) {
                ++$awaitingCount;

                continue;
            }
            if ($this->classifier->isFullyHealthy($domain)) {
                ++$healthyCount;
            } else {
                ++$attentionCount;
            }
        }

        $activeFilter = $request->query->getString('status');
        $domains = match ($activeFilter) {
            'healthy' => array_values(array_filter(
                $allDomains,
                fn (DnsHealthOverviewResult $d): bool => $d->hasSnapshot() && $this->classifier->isFullyHealthy($d),
            )),
            'attention' => array_values(array_filter(
                $allDomains,
                fn (DnsHealthOverviewResult $d): bool => $d->hasSnapshot() && !$this->classifier->isFullyHealthy($d),
            )),
            'unchecked' => array_values(array_filter(
                $allDomains,
                static fn (DnsHealthOverviewResult $d): bool => !$d->hasSnapshot(),
            )),
            default => $allDomains,
        };

        // Surface only the four canonical filters; anything else collapses
        // to "no filter" so a garbage URL doesn't render an empty page.
        $activeFilter = in_array($activeFilter, ['healthy', 'attention', 'unchecked'], true)
            ? $activeFilter
            : '';

        return $this->render('dashboard/dns_health_overview.html.twig', [
            'domains' => $domains,
            'totalCount' => $totalCount,
            'healthyCount' => $healthyCount,
            'attentionCount' => $attentionCount,
            'awaitingCount' => $awaitingCount,
            'activeFilter' => $activeFilter,
        ]);
    }
}
