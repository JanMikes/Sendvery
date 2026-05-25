<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDnsHealthOverview;
use App\Query\GetDomainOverview;
use App\Results\DnsHealthOverviewResult;
use App\Services\DashboardContext;
use App\Services\DomainHealthClassifier;
use App\Value\DomainHealthFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListDomainsController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainOverview $getDomainOverview,
        private readonly GetDnsHealthOverview $getDnsHealthOverview,
        private readonly DomainHealthClassifier $domainHealthClassifier,
    ) {
    }

    #[Route('/app/domains', name: 'dashboard_domains')]
    public function __invoke(Request $request): Response
    {
        $teamIdStrings = $this->dashboardContext->getTeamIdStrings();
        $statusFilterRaw = $request->query->getString('status', '');
        $statusFilter = DomainHealthFilter::tryFrom($statusFilterRaw);
        $totalDomainCount = $this->getDomainOverview->countForTeams($teamIdStrings);

        // TASK-130: pull the DNS health snapshot map for every domain so the
        // merged /app/domains page can render the 4-card stat summary (counts
        // are always derived from the unfiltered set so the chips link to a
        // known target) and the per-card grade chip + protocol badges.
        $dnsHealthAll = $this->getDnsHealthOverview->forTeams($teamIdStrings);
        $dnsHealthByDomain = [];
        foreach ($dnsHealthAll as $dnsHealth) {
            $dnsHealthByDomain[$dnsHealth->domainId] = $dnsHealth;
        }

        $totalDnsCount = count($dnsHealthAll);
        $healthyCount = 0;
        $attentionCount = 0;
        $awaitingCount = 0;
        foreach ($dnsHealthAll as $dnsHealth) {
            if (!$dnsHealth->hasSnapshot()) {
                ++$awaitingCount;

                continue;
            }
            if ($this->domainHealthClassifier->isFullyHealthy($dnsHealth)) {
                ++$healthyCount;
            } else {
                ++$attentionCount;
            }
        }

        // TASK-130: ?status=unchecked is the new fourth filter chip absorbed
        // from the deleted /app/dns-health page. Handled here (not via
        // DomainHealthFilter enum) because the "no snapshot yet" predicate is
        // a DnsHealthOverviewResult property — not a state the
        // DomainHealthClassifier carries on a DomainOverviewResult.
        if ('unchecked' === $statusFilterRaw) {
            $uncheckedDomainIds = [];
            foreach ($dnsHealthAll as $dnsHealth) {
                if (!$dnsHealth->hasSnapshot()) {
                    $uncheckedDomainIds[$dnsHealth->domainId] = true;
                }
            }
            $allDomains = $this->getDomainOverview->forTeams($teamIdStrings, null);
            $domains = array_values(array_filter(
                $allDomains,
                static fn ($domain): bool => isset($uncheckedDomainIds[$domain->domainId]),
            ));
        } else {
            $domains = $this->getDomainOverview->forTeams($teamIdStrings, $statusFilter);
        }

        // TASK-098: severity per card now comes from the unified
        // `DomainHealthClassifier` (same service the detail-page banner uses).
        // Pre-compute as a domain-id → severity map so the template stays
        // logic-free — Twig doesn't speak service injection per-row, and we
        // want a single instantiation point rather than one classifier call
        // buried inside a component prop.
        $severityByDomain = [];
        foreach ($domains as $domain) {
            $severityByDomain[$domain->domainId] = $this->domainHealthClassifier->classifyOverview($domain);
        }

        return $this->render('dashboard/domains.html.twig', [
            'domains' => $domains,
            // Show the Team column only when the user actually belongs to
            // more than one team — single-team users would just see a noisy
            // column repeating the same name on every row.
            'showTeamColumn' => count($this->dashboardContext->getTeamIds()) > 1,
            'activeFilter' => $statusFilter,
            'activeFilterRaw' => $this->normaliseFilterRaw($statusFilterRaw),
            'totalDomainCount' => $totalDomainCount,
            'severityByDomain' => $severityByDomain,
            'dnsHealthByDomain' => $dnsHealthByDomain,
            'totalDnsCount' => $totalDnsCount,
            'healthyCount' => $healthyCount,
            'attentionCount' => $attentionCount,
            'awaitingCount' => $awaitingCount,
        ]);
    }

    /**
     * The chip row needs to know which chip to highlight as active. Mirror
     * the controller's tolerant input handling: anything outside the four
     * canonical values collapses to "no filter" so a garbage URL doesn't
     * paint a misleading active state.
     */
    private function normaliseFilterRaw(string $raw): string
    {
        return in_array($raw, ['healthy', 'attention', 'unverified', 'unchecked'], true)
            ? $raw
            : '';
    }
}
