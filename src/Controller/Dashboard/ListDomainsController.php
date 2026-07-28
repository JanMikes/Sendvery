<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDnsHealthOverview;
use App\Query\GetDomainOverview;
use App\Query\GetLatestDnsCheckStatesForDomains;
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
        private readonly GetLatestDnsCheckStatesForDomains $getLatestDnsCheckStatesForDomains,
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

        // Every domain, unfiltered — the population the stat cards count. Always
        // loaded, even when a filter is active, because a filtered count would
        // make each card report on itself ("Need attention 1" on the attention
        // list, always). The unfiltered list is reused as `$domains` below when
        // no filter is set, so the extra round-trip is paid only on a filtered
        // view.
        $allDomains = $this->getDomainOverview->forTeams($teamIdStrings, null);

        // The "Fully healthy" and "Need attention" cards are anchors to
        // ?status=healthy and ?status=attention, so the number IS a claim about
        // that list and has to be produced by the rule that builds it.
        //
        // These two used to be counted with `hasSnapshot()` + `isFullyHealthy()`
        // — a THIRD rule, asking only "are all four protocols configured?" with
        // no DMARC-verified precedence and no pass-rate arm. So a domain with a
        // 30% pass rate was counted "Fully healthy" while its own card badge and
        // the attention list both said Attention, and a domain with a valid
        // DMARC record that does not route reports to us was counted "Fully
        // healthy" while appearing in no list at all. Three rules on one page.
        //
        // `classifyOverview()` is the same call that paints each card's badge
        // two blocks down and the same rule `GetDomainOverview` now transcribes
        // into the ?status= SQL, so the stat, the badge and the list are one
        // answer. Unverified domains fall into neither count — deliberately:
        // both chips require a verified DMARC record, and the page has no
        // Unverified stat card, only an Unverified filter chip.
        $healthyCount = 0;
        $attentionCount = 0;
        foreach ($allDomains as $domain) {
            match ($this->domainHealthClassifier->classifyOverview($domain)) {
                DomainHealthFilter::Healthy => ++$healthyCount,
                DomainHealthFilter::Attention => ++$attentionCount,
                DomainHealthFilter::Unverified => null,
            };
        }

        // "Awaiting first check" stays on the snapshot axis it genuinely owns:
        // it answers "has the nightly sweep ever run for this domain?", which is
        // not a health verdict and has its own ?status=unchecked filter below.
        $uncheckedDomainIds = [];
        foreach ($dnsHealthAll as $dnsHealth) {
            if (!$dnsHealth->hasSnapshot()) {
                $uncheckedDomainIds[$dnsHealth->domainId] = true;
            }
        }
        $awaitingCount = count($uncheckedDomainIds);

        // TASK-130: ?status=unchecked is the new fourth filter chip absorbed
        // from the deleted /app/dns-health page. Handled here (not via
        // DomainHealthFilter enum) because the "no snapshot yet" predicate is
        // a DnsHealthOverviewResult property — not a state the
        // DomainHealthClassifier carries on a DomainOverviewResult.
        if ('unchecked' === $statusFilterRaw) {
            $domains = array_values(array_filter(
                $allDomains,
                static fn ($domain): bool => isset($uncheckedDomainIds[$domain->domainId]),
            ));
        } elseif (null === $statusFilter) {
            $domains = $allDomains;
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

        // The four protocol badges on each card read the newest stored
        // `dns_check_result` row — the same authoritative source the domain
        // detail checklist uses — instead of the `*_verified_at` columns and
        // the nightly `domain_health_snapshot`.
        //
        // Both of the old sources lied, in opposite directions. `isSpfVerified()`
        // returns a bool, never null, so a domain whose first check had not
        // landed yet showed three red "not verified" badges about records we had
        // never looked at. And CheckDomainDnsHandler only ever SETS those
        // columns — an invalid result leaves the old timestamp in place — so a
        // domain whose SPF record was deleted last month kept a green "SPF
        // verified" badge forever. Domains absent from this map have no check
        // row at all, which the card renders as no badge rather than a verdict.
        $protocolStatesByDomain = $this->getLatestDnsCheckStatesForDomains->forDomains(
            array_values(array_map(static fn ($domain): string => $domain->domainId, $domains)),
            $teamIdStrings,
        );

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
            'protocolStatesByDomain' => $protocolStatesByDomain,
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
