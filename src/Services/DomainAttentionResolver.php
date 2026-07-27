<?php

declare(strict_types=1);

namespace App\Services;

use App\Query\GetDnsHealthOverview;
use App\Query\GetLatestDnsCheckStatesForDomains;
use App\Results\DnsHealthOverviewResult;
use App\Results\DomainAttentionListResult;
use App\Results\DomainAttentionResult;
use App\Results\DomainOverviewResult;
use App\Results\DomainSetupStatus;
use App\Results\DomainVerificationStatusResult;
use App\Services\Dns\RuaScenarioResolver;
use App\Value\DomainAttentionReason;
use App\Value\DomainHealthFilter;
use App\Value\DomainSetupDisplayMode;
use App\Value\DomainVerificationSeverity;
use App\Value\ProtocolState;

/**
 * Builds the `/app` "Needs your attention" list: which domains are not healthy,
 * the specific failing thing on each, and the deep link to where it gets fixed.
 *
 * WHY IT BORROWS INSTEAD OF DECIDING. Both halves of the answer already exist
 * per-domain, and re-deriving either one here is how the dashboard would start
 * contradicting the page it links to:
 *
 *  - WHICH domains → {@see DomainHealthClassifier::classifyOverview()}, the same
 *    call {@see HealthSummaryResolver} counts with and `/app/domains` colours its
 *    cards with. So "3 domains need attention" in the summary and three rows in
 *    this list are the same three domains by construction, not by coincidence.
 *  - WHY, and WHERE TO GO → {@see DomainSetupStatusResolver::resolve()}, the
 *    resolver behind the per-domain banner and the guided DNS setup surface.
 *    Its `headline`, its per-protocol `statusLine`s and its `ctaRoute` +
 *    `ctaFragment` are passed through verbatim; this class writes no DNS copy of
 *    its own.
 *
 * The one reason it adds is the 30-day pass rate, which the per-protocol rows
 * structurally cannot express — see {@see self::buildReasons()}.
 *
 * COST. Every input is batched: one DNS-health query, one per-protocol check
 * query and one RUA-scenario query for the whole page, and the last two are
 * scoped to the handful of domains actually rendered. Classification is pure, so
 * the full domain set is filtered in memory from data the controller already
 * fetched.
 */
final readonly class DomainAttentionResolver
{
    /**
     * The dashboard is a triage surface, not the domains list. Five rows is
     * about what fits before the supporting detail below the fold; anything
     * past that is one link away with the full set.
     */
    public const int DEFAULT_LIMIT = 5;

    /**
     * Enough to name the blocker and its immediate neighbours. Past three the
     * row stops being scannable, which is the failure mode of showing a domain
     * four equally-red rows and calling it guidance.
     */
    private const int MAX_REASONS_SHOWN = 3;

    /**
     * The four records a domain's health verdict is actually made of. The 5th
     * `RUA destination` row is excluded for the same reason
     * {@see DomainSetupStatusResolver} excludes it from its `allConfigured`
     * check: reports reaching an inbox the user owns is a valid setup, not a
     * broken record.
     */
    private const array CORE_PROTOCOL_NAMES = ['SPF', 'DKIM', 'DMARC', 'MX'];

    public function __construct(
        private DomainHealthClassifier $domainHealthClassifier,
        private DomainSetupStatusResolver $domainSetupStatusResolver,
        private GetDnsHealthOverview $getDnsHealthOverview,
        private GetLatestDnsCheckStatesForDomains $getLatestDnsCheckStates,
        private RuaScenarioResolver $ruaScenarioResolver,
        private IngestionHealthReader $ingestionHealth,
    ) {
    }

    /**
     * `$verificationStatus` / `$verificationSeverity` / `$quarantineCount` carry
     * the DMARC verification NUANCE that `/app` used to render as a fourth
     * stacked banner: "still propagating, nothing to worry about yet", "we saw
     * your record before and now we can't", "reports are already queued up
     * waiting for you". None of it is derivable from the per-protocol rows, and
     * all of it is about one specific domain — so it now rides on that domain's
     * row in this list instead of on a page-level banner that could only ever
     * speak about the most recently added domain.
     *
     * @param array<DomainOverviewResult> $domains         every monitored domain in scope, as already fetched by the caller
     * @param list<string>                $teamIds         team UUIDs the caller is allowed to read from
     * @param int                         $quarantineCount reports parked for the verification-status domain specifically
     */
    public function resolve(
        array $domains,
        array $teamIds,
        int $limit = self::DEFAULT_LIMIT,
        ?DomainVerificationStatusResult $verificationStatus = null,
        ?DomainVerificationSeverity $verificationSeverity = null,
        int $quarantineCount = 0,
    ): DomainAttentionListResult {
        $candidates = array_values(array_filter(
            $domains,
            fn (DomainOverviewResult $domain): bool => DomainHealthFilter::Healthy !== $this->domainHealthClassifier->classifyOverview($domain),
        ));

        if ([] === $candidates) {
            return new DomainAttentionListResult(domains: [], totalCount: 0, hiddenCount: 0);
        }

        usort($candidates, $this->compareUrgency(...));

        $totalCount = count($candidates);
        $visible = array_slice($candidates, 0, max(0, $limit));
        $visibleIds = array_values(array_map(
            static fn (DomainOverviewResult $domain): string => $domain->domainId,
            $visible,
        ));

        $dnsHealthByDomain = $this->indexDnsHealthByDomain($teamIds);
        $protocolStatesByDomain = $this->getLatestDnsCheckStates->forDomains($visibleIds, $teamIds);
        $ruaScenarios = $this->ruaScenarioResolver->resolveForDomainIds($visibleIds);

        $rows = [];
        foreach ($visible as $domain) {
            $status = $this->domainSetupStatusResolver->resolve(
                $dnsHealthByDomain[$domain->domainId] ?? null,
                $ruaScenarios[$domain->domainId] ?? null,
                $domain,
                $domain->domainId,
                $protocolStatesByDomain[$domain->domainId] ?? [],
            );

            $rows[] = $this->buildRow(
                $domain,
                $status,
                null !== $verificationStatus && null !== $verificationSeverity && $verificationStatus->domainId === $domain->domainId
                    ? $this->verificationReason($verificationStatus, $verificationSeverity, $quarantineCount)
                    : null,
            );
        }

        return new DomainAttentionListResult(
            domains: $rows,
            totalCount: $totalCount,
            hiddenCount: $totalCount - count($rows),
        );
    }

    private function buildRow(
        DomainOverviewResult $domain,
        DomainSetupStatus $status,
        ?DomainAttentionReason $verificationReason,
    ): DomainAttentionResult {
        // PanelOnly means the resolver has no verdict yet because no DNS check
        // has finished — the per-domain page hides its banner entirely in this
        // state and shows an in-progress panel instead. Reading `headline` here
        // would surface the placeholder copy that page deliberately suppresses.
        $checkInProgress = DomainSetupDisplayMode::PanelOnly === $status->displayMode;

        if ($checkInProgress) {
            return new DomainAttentionResult(
                domainId: $domain->domainId,
                domainName: $domain->domainName,
                severity: $status->severity,
                // Same sentence the guided setup surface leads with for this
                // state (App\Services\Dns\GuidedDnsSetupResolver), so following
                // the link does not change the story.
                headline: 'Checking your DNS now',
                reasons: array_values(array_filter([
                    // Kept even here: "reports are already queued for you" is the
                    // single most motivating thing we can tell someone whose very
                    // first DNS check has not landed yet.
                    $verificationReason,
                    new DomainAttentionReason(
                        label: 'DNS check',
                        detail: 'The first check is still running — results usually land within a couple of minutes.',
                        tone: 'info',
                    ),
                ])),
                ctaLabel: 'See progress',
                ctaRoute: 'dashboard_domain_health',
                ctaRouteParams: ['id' => $domain->domainId],
                passRate: $domain->passRate,
                awaitingFirstReport: $domain->isAwaitingFirstReport(),
                checkInProgress: true,
            );
        }

        // The verification nuance leads: it is the only reason carrying HISTORY
        // ("we saw this record before"), which changes what the user should do
        // about an otherwise identical "record not detected" finding.
        $reasons = null === $verificationReason
            ? $this->buildReasons($status, $domain)
            : [$verificationReason, ...$this->buildReasons($status, $domain)];
        $headline = $status->headline;

        if ([] === $reasons) {
            // A row with a severity and nothing to explain it is worse than no
            // row. This is reachable when the health verdict and the
            // per-protocol rows read different sources for the same domain: the
            // classifier looks at the verified-at columns plus the latest health
            // snapshot, the rows look at the stored check results. Every check
            // path writes both, so they agree in practice — but a domain missing
            // its snapshot must still say something true rather than borrow the
            // status headline, which in this state reads "all records in place".
            $headline = 'Waiting on a full DNS check';
            $reasons = [new DomainAttentionReason(
                label: 'DNS verification',
                detail: 'Not every record is confirmed yet — open the domain to see its latest check.',
                tone: 'info',
            )];
        }

        return new DomainAttentionResult(
            domainId: $domain->domainId,
            domainName: $domain->domainName,
            severity: $status->severity,
            headline: $headline,
            reasons: array_slice($reasons, 0, self::MAX_REASONS_SHOWN),
            ctaLabel: $status->ctaLabel ?? 'Open domain',
            // No CTA on the status means every record is in place and the
            // domain is here on pass rate alone; the detail page (charts, top
            // senders) is where that gets investigated, not the DNS surface.
            ctaRoute: null !== $status->ctaRoute && null !== $status->ctaLabel
                ? $status->ctaRoute
                : 'dashboard_domain_detail',
            ctaRouteParams: $this->buildCtaRouteParams($domain->domainId, $status),
            passRate: $domain->passRate,
            awaitingFirstReport: $domain->isAwaitingFirstReport(),
            checkInProgress: false,
            hiddenReasons: max(0, count($reasons) - self::MAX_REASONS_SHOWN),
        );
    }

    /**
     * @return array<string, string>
     */
    private function buildCtaRouteParams(string $domainId, DomainSetupStatus $status): array
    {
        $params = ['id' => $domainId];

        if (null !== $status->ctaRoute && null !== $status->ctaLabel && null !== $status->ctaFragment) {
            $params['_fragment'] = $status->ctaFragment;
        }

        return $params;
    }

    /**
     * The DMARC verification story for one domain, in the words `/app` used
     * before this moved off a page-level banner and onto the domain's own row.
     *
     * Four states worth telling apart, and the difference between them is
     * entirely historical — the per-protocol DMARC row says "not detected" for
     * three of them:
     *
     *  - Verified recently, one failed check: DNS is still propagating. Info
     *    tone on purpose; escalating here trains users to ignore us.
     *  - Never verified, but reports are already queued: the strongest possible
     *    nudge to finish DNS, and unavailable from DNS state alone.
     *  - Verified once, failing now: a REGRESSION. Something that used to work
     *    stopped, which is a different action than "you never set this up".
     *  - Verified, published, still nothing after 48h: the record exists but the
     *    `rua=` tag is not pointing anywhere we can read.
     *
     * Returns null when severity is Ok (nothing to add) and for the plain
     * never-verified case, where the DMARC protocol row already says it.
     */
    private function verificationReason(
        DomainVerificationStatusResult $status,
        DomainVerificationSeverity $severity,
        int $quarantineCount,
    ): ?DomainAttentionReason {
        if (DomainVerificationSeverity::Ok === $severity) {
            return null;
        }

        if (null === $status->dmarcVerifiedAt) {
            if (0 === $quarantineCount) {
                return null;
            }

            return new DomainAttentionReason(
                label: 'Reports already waiting',
                detail: sprintf(
                    '%d DMARC report%s arrived for this domain and %s parked safely until DNS verification completes.',
                    $quarantineCount,
                    1 === $quarantineCount ? '' : 's',
                    1 === $quarantineCount ? 'is' : 'are',
                ),
                tone: 'warning',
            );
        }

        if (DomainVerificationSeverity::Info === $severity) {
            return new DomainAttentionReason(
                label: 'Confirming DMARC record',
                detail: "The latest check didn't see your DMARC record — most likely DNS is still propagating, or a resolver hiccup. We'll keep checking; nothing to worry about yet.",
                tone: 'info',
            );
        }

        if ($status->consecutiveDmarcFailures > 0) {
            return new DomainAttentionReason(
                label: 'DMARC record went missing',
                detail: sprintf(
                    "We saw it before, but the last %d checks couldn't find it. Reports will stop arriving until it's republished.",
                    $status->consecutiveDmarcFailures,
                ),
                tone: 'error',
            );
        }

        // The record is published and the latest check passed, so the reason
        // reports are missing is either the rua= tag or us. Only say the former
        // when we can show the latter is working — see NoReportsExplanation.
        return NoReportsExplanation::forPipelineHealth(
            $this->ingestionHealth->isCentralInboxProvenHealthy(),
        )->toAttentionReason();
    }

    /**
     * @return list<DomainAttentionReason>
     */
    private function buildReasons(DomainSetupStatus $status, DomainOverviewResult $domain): array
    {
        $leading = [];
        $trailing = [];
        $coreFailures = 0;

        foreach ($status->protocols as $protocol) {
            if (ProtocolState::Configured === $protocol->state) {
                continue;
            }

            if (in_array($protocol->name, self::CORE_PROTOCOL_NAMES, true)) {
                ++$coreFailures;
            }

            $reason = new DomainAttentionReason(
                label: $protocol->name,
                detail: $protocol->statusLine,
                // Missing = nothing published, so the record cannot work at all.
                // Invalid = something is published but wrong, which still lets
                // some mail through. The split matches the add-vs-edit
                // distinction the guided setup surface draws.
                tone: ProtocolState::Missing === $protocol->state ? 'error' : 'warning',
            );

            // Ordering is borrowed, not re-decided: whichever protocol the
            // status CTA jumps to is the one the fix surface considers most
            // urgent, so it leads here too. A second priority table in this
            // class would be free to drift from that one.
            if (null !== $status->ctaFragment && $protocol->healthAnchor === $status->ctaFragment) {
                $leading[] = $reason;

                continue;
            }

            $trailing[] = $reason;
        }

        $reasons = [...$leading, ...$trailing];

        // Pass rate is the one reason no protocol row can carry. With all four
        // records in place the classifier can still return Attention, and by
        // elimination a sub-threshold 30-day pass rate is why — see
        // DomainHealthClassifier. Null pass rate is excluded per the no-data
        // contract on DomainOverviewResult: nothing to grade is not a failure.
        $passRate = $domain->passRate;
        if (0 === $coreFailures && DomainHealthFilter::Attention === $status->severity && null !== $passRate) {
            $reasons[] = new DomainAttentionReason(
                label: 'DMARC pass rate',
                detail: sprintf(
                    'Only %s%% of messages passed DMARC in the last 30 days',
                    number_format($passRate, 1),
                ),
                tone: 'warning',
            );
        }

        return $reasons;
    }

    /**
     * Unverified outranks Attention — a domain we receive nothing for cannot be
     * improved by tuning anything else. Within a bucket the worst measured pass
     * rate leads, and a domain with no pass-rate data yet sorts last rather than
     * first: an absence of reports is not a 0% score.
     */
    private function compareUrgency(DomainOverviewResult $a, DomainOverviewResult $b): int
    {
        $byBucket = $this->urgencyRank($a) <=> $this->urgencyRank($b);
        if (0 !== $byBucket) {
            return $byBucket;
        }

        $byPassRate = ($a->passRate ?? PHP_FLOAT_MAX) <=> ($b->passRate ?? PHP_FLOAT_MAX);
        if (0 !== $byPassRate) {
            return $byPassRate;
        }

        return strcmp($a->domainName, $b->domainName);
    }

    private function urgencyRank(DomainOverviewResult $domain): int
    {
        return DomainHealthFilter::Unverified === $this->domainHealthClassifier->classifyOverview($domain) ? 0 : 1;
    }

    /**
     * @param list<string> $teamIds
     *
     * @return array<string, DnsHealthOverviewResult>
     */
    private function indexDnsHealthByDomain(array $teamIds): array
    {
        $byDomain = [];
        foreach ($this->getDnsHealthOverview->forTeams($teamIds) as $dnsHealth) {
            $byDomain[$dnsHealth->domainId] = $dnsHealth;
        }

        return $byDomain;
    }
}
