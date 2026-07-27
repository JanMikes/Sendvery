<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Query\GetDnsHealthOverview;
use App\Query\GetDomainOverview;
use App\Query\GetLatestDnsCheckStates;
use App\Query\GetTeamPlan;
use App\Repository\DnsCheckResultRepository;
use App\Repository\MonitoredDomainRepository;
use App\Services\DashboardContext;
use App\Services\DomainSetupStatusResolver;
use App\Services\Stripe\PlanEnforcement;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\GuidedDnsSetupView;
use App\Value\Dns\ManagedDeliveryContext;
use App\Value\DnsCheckType;
use App\Value\DomainSetupDisplayMode;
use Ramsey\Uuid\Uuid;

/**
 * Single assembly point for the guided DNS setup surface.
 *
 * Three controllers render that surface — the domain detail page, the domain
 * health page and the turbo-frame poll endpoint. Wiring the same seven queries
 * into each of them is how the two page versions drifted apart in the first
 * place, so the wiring lives here once and every surface is fed from the same
 * resolved state.
 *
 * Team scoping is enforced here rather than trusted from the caller: every read
 * goes through {@see DashboardContext}, and an id belonging to another tenant
 * returns null so the caller can 404.
 */
final readonly class GuidedDnsSetupProvider
{
    public function __construct(
        private DashboardContext $dashboardContext,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private GetDnsHealthOverview $getDnsHealthOverview,
        private GetLatestDnsCheckStates $getLatestDnsCheckStates,
        private GetDomainOverview $getDomainOverview,
        private DnsCheckResultRepository $dnsCheckResultRepository,
        private RuaScenarioResolver $ruaScenarioResolver,
        private DomainSetupStatusResolver $domainSetupStatusResolver,
        private GuidedDnsSetupResolver $guidedDnsSetupResolver,
        private GetTeamPlan $getTeamPlan,
        private CloudflareDnsClient $cloudflareClient,
        private PlanEnforcement $planEnforcement,
        private ManagedDmarcCnameChecker $cnameChecker,
    ) {
    }

    public function forDomainId(string $domainId): ?GuidedDnsSetupView
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $domainUuid = Uuid::fromString($domainId);

        $domain = $this->monitoredDomainRepository->findForTeams(
            $domainUuid,
            $this->dashboardContext->getTeamIds(),
        );

        if (null === $domain) {
            return null;
        }

        // The newest stored check per protocol, in one round trip. This is what
        // makes the per-protocol verdicts independent of the nightly snapshot
        // cron — see App\Query\GetLatestDnsCheckStates for the bug that forced
        // the change.
        $protocolStates = $this->getLatestDnsCheckStates->forDomain($domainId, $teamIds);

        // The recommender needs the full check ENTITIES (it reads `details` for
        // the SPF lookup count), which the state query deliberately does not
        // carry. Loading them lazily — only when a check exists at all — keeps
        // the freshly-added-domain path down to the single state query.
        $latestByType = [] === $protocolStates
            ? [
                DnsCheckType::Spf->value => null,
                DnsCheckType::Dkim->value => null,
                DnsCheckType::Dmarc->value => null,
                DnsCheckType::Mx->value => null,
            ]
            : [
                DnsCheckType::Spf->value => $this->dnsCheckResultRepository->findLatestForDomainAndType($domainUuid, DnsCheckType::Spf),
                DnsCheckType::Dkim->value => $this->dnsCheckResultRepository->findLatestForDomainAndType($domainUuid, DnsCheckType::Dkim),
                DnsCheckType::Dmarc->value => $this->dnsCheckResultRepository->findLatestForDomainAndType($domainUuid, DnsCheckType::Dmarc),
                DnsCheckType::Mx->value => $this->dnsCheckResultRepository->findLatestForDomainAndType($domainUuid, DnsCheckType::Mx),
            ];

        $ruaScenario = $this->ruaScenarioResolver->resolveForDomainId($domainUuid);

        $setupStatus = $this->domainSetupStatusResolver->resolve(
            $this->getDnsHealthOverview->forDomain($domainId, $teamIds),
            $ruaScenario,
            $this->getDomainOverview->forDomain($domainId, $teamIds),
            $domainId,
            $protocolStates,
        );

        // Managed-DMARC gating is read from the SAME two sources the
        // ManagedDmarcCard uses (Cloudflare configured + PlanEnforcement), so
        // the guided surface and the card can never disagree about whether the
        // managed path is available for this team.
        $plan = $this->getTeamPlan->forTeam($this->dashboardContext->getTeamId()->toString());
        $dnsAutomationConfigured = $this->cloudflareClient->isConfigured();

        // The one live DNS read on this path. A `_dmarc` TXT record the customer
        // still owns blocks the managed CNAME outright (RFC 1034 §3.6.2), and we
        // are about to tell someone to DELETE a record — an answer from the
        // cached nightly check, possibly hours old, is not good enough for that.
        // Narrowly gated so it only runs for the domains it can change the
        // advice for: managed path chosen, CNAME not verified yet, first check
        // already landed (the in-progress state publishes no verdicts at all).
        $conflictingDmarcTxt = DmarcSetupMode::ManagedCname === $domain->dmarcSetupMode
            && null === $domain->cnameVerifiedAt
            && DomainSetupDisplayMode::PanelOnly !== $setupStatus->displayMode
                ? $this->cnameChecker->findConflictingDmarcTxt($domain->domain)
                : null;

        $setup = $this->guidedDnsSetupResolver->resolve(
            $domain,
            $setupStatus,
            $latestByType,
            new ManagedDeliveryContext(
                dnsAutomationConfigured: $dnsAutomationConfigured,
                managedAvailable: $dnsAutomationConfigured && $this->planEnforcement->canUseManagedDmarc($plan),
                nextTier: $plan->nextTier(),
                cnameTarget: $this->cnameChecker->expectedTarget($domain->domain),
                conflictingDmarcTxt: $conflictingDmarcTxt,
            ),
            // Carries the published `rua=` address count, which decides whether
            // appending Sendvery risks tripping the RFC 7489 two-address cap.
            $ruaScenario,
        );

        return new GuidedDnsSetupView(
            domainId: $domainId,
            domainName: $domain->domain,
            setup: $setup,
            setupStatus: $setupStatus,
            ruaScenario: $ruaScenario,
            latestByType: $latestByType,
            conflictingDmarcTxt: $conflictingDmarcTxt,
        );
    }
}
