<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\SetupChecklistResult;
use App\Value\Dns\RuaScenario;
use App\Value\SetupChecklistDomain;
use App\Value\SetupChecklistStep;

/**
 * Pure-computation service that builds the onboarding setup checklist for
 * the dashboard overview. Mirrors {@see NextActionResolver}: caller assembles
 * the inputs (already-fetched team state), the resolver returns the typed
 * result with all copy baked in so the template is presentation-only.
 *
 * Visibility rule:
 *  - Hidden when every step is complete (the checklist has no purpose).
 *  - Hidden when the team dismissed it AND there is no DMARC regression.
 *  - The dismissal is overridden ONLY when a previously-completed DMARC step
 *    has regressed (verified at some point + consecutive failures now).
 *    This lets us avoid clearing the dismissal column on every DNS check.
 *
 * ONE FOCUSED DOMAIN, NAMED. Every step that is still open names
 * `$focusDomain` and deep-links to that domain's DNS setup surface, because
 * "Publish your DMARC record → Do it" told a multi-domain user nothing about
 * WHICH domain was meant. The checklist is scoped to one domain rather than
 * repeated per domain on purpose: it is a one-time onboarding track that
 * disappears for good once the team is set up, whereas "which of my domains is
 * broken right now, and why" is a permanent question answered by the attention
 * list ({@see DomainAttentionResolver}) directly below it. A per-domain
 * checklist would be a second, worse copy of that list — 3 rows per domain,
 * forever. Any other domain still missing DMARC is surfaced as a plain link in
 * `otherUnfinishedDomains` so it is one click away without repeating the track.
 *
 * COMPLETION STAYS TEAM-WIDE. `anyDomainHasDmarcVerified` /
 * `anyDomainHasFirstReport` decide whether a step is ticked, so adding a fresh
 * domain to an established team does not re-open "Finish setting up Sendvery".
 * The focus domain only decides what an OPEN step is called and where its CTA
 * goes — and an open DMARC step means no domain is verified yet, so any
 * unverified domain is a truthful target.
 *
 * Scenario branching (TASK-128): the third step ("Receive your first DMARC
 * report") tailors its copy + CTA to the headline domain's
 * {@see RuaScenario}. When `rua=` already points at Sendvery, telling the
 * user to "connect a mailbox if you prefer" contradicts the correctly-
 * configured state they just published, so the alternative is suppressed
 * entirely. The branching uses the same headline-domain scenario as
 * {@see NextActionResolver}, so the two cards stay in lockstep.
 */
final readonly class SetupChecklistResolver
{
    /**
     * @param ?SetupChecklistDomain      $focusDomain            the domain open steps name + link to; null only when the team has no domains yet
     * @param list<SetupChecklistDomain> $otherUnfinishedDomains further domains still missing a verified DMARC record, for the "also needs setup" links
     */
    public function resolve(
        int $domainCount,
        bool $anyDomainHasDmarcVerified,
        bool $anyDomainHasFirstReport,
        bool $hasMailbox,
        ?\DateTimeImmutable $dismissedAt,
        bool $hasDmarcRegression,
        ?RuaScenario $headlineDomainRuaScenario,
        ?SetupChecklistDomain $focusDomain = null,
        array $otherUnfinishedDomains = [],
    ): SetupChecklistResult {
        $addDomainStep = new SetupChecklistStep(
            id: 'add_domain',
            title: 'Add your first domain',
            description: 'Sendvery monitors DMARC reports delivered to your domains. Add one to get started.',
            actionRoute: 'dashboard_domain_add',
            actionLabel: 'Add domain',
            actionRouteParams: [],
            isComplete: $domainCount > 0,
        );

        $publishDmarcStep = new SetupChecklistStep(
            id: 'publish_dmarc',
            title: null === $focusDomain
                ? 'Publish your DMARC record'
                : sprintf('Publish the DMARC record for %s', $focusDomain->name),
            description: 'Add a DMARC TXT record so email receivers know where to send aggregate reports.',
            // Land on the domain's own guided DNS surface with the DMARC step
            // already in view, not on the domains list the user then has to
            // navigate from. Same route + anchor the per-domain status banner's
            // "Set up DMARC" CTA uses.
            actionRoute: null === $focusDomain ? 'dashboard_domains' : 'dashboard_domain_health',
            actionLabel: null === $focusDomain ? 'Do it' : sprintf('Set up %s', $focusDomain->name),
            actionRouteParams: null === $focusDomain
                ? []
                : ['id' => $focusDomain->id, '_fragment' => 'health-dmarc'],
            isComplete: $anyDomainHasDmarcVerified,
        );

        $receiveReportsStep = $this->buildReceiveReportsStep(
            anyDomainHasFirstReport: $anyDomainHasFirstReport,
            hasMailbox: $hasMailbox,
            headlineDomainRuaScenario: $headlineDomainRuaScenario,
            focusDomain: $focusDomain,
        );

        $steps = [$addDomainStep, $publishDmarcStep, $receiveReportsStep];
        $completedCount = (int) $addDomainStep->isComplete
            + (int) $publishDmarcStep->isComplete
            + (int) $receiveReportsStep->isComplete;
        $totalCount = count($steps);
        $isFullyComplete = $completedCount === $totalCount;

        // Auto-un-dismiss: only when DMARC was once verified and we're now
        // seeing a regression. Without the `$publishDmarcStep->isComplete`
        // gate, a never-verified domain that "fails" looks identical to a
        // regression — but that's just the initial unverified state, which
        // dismissal already covers.
        $regressionOverridesDismissal = $hasDmarcRegression && $publishDmarcStep->isComplete;
        $isDismissed = null !== $dismissedAt && !$regressionOverridesDismissal;

        $isVisible = !$isFullyComplete && !$isDismissed;

        return new SetupChecklistResult(
            steps: $steps,
            completedCount: $completedCount,
            totalCount: $totalCount,
            isVisible: $isVisible,
            isFullyComplete: $isFullyComplete,
            focusDomainName: $focusDomain?->name,
            // Only meaningful while the DMARC step is open: once one domain is
            // verified the step is ticked and "these others also need setup"
            // would be pointing at work this card is no longer tracking.
            otherUnfinishedDomains: $publishDmarcStep->isComplete ? [] : $otherUnfinishedDomains,
        );
    }

    /**
     * TASK-128: branch the third step's copy + CTA on the headline domain's
     * RUA scenario so the alternative actions match the user's reality.
     *
     * - `PointsAtSendvery` — reports flow automatically; no mailbox CTA. The
     *   primary action becomes a passive "Check DNS setup" deep-link.
     * - `PointsAtExternal` — DMARC routes elsewhere; surface the matching
     *   "Connect that mailbox" alternative (the NextAction card carries the
     *   richer scenario-(c) copy, this is the checklist-row mirror).
     * - `NoRecord` / null — keep the original generic copy that nudges the
     *   user toward publishing DMARC + connecting a mailbox as a fallback.
     */
    private function buildReceiveReportsStep(
        bool $anyDomainHasFirstReport,
        bool $hasMailbox,
        ?RuaScenario $headlineDomainRuaScenario,
        ?SetupChecklistDomain $focusDomain,
    ): SetupChecklistStep {
        $isComplete = $anyDomainHasFirstReport || $hasMailbox;
        $title = null === $focusDomain
            ? 'Receive your first DMARC report'
            : sprintf('Receive the first DMARC report for %s', $focusDomain->name);

        // The mailbox route is domain-agnostic, so scenario (c) keeps its
        // generic params; the two DNS-facing branches deep-link the focused
        // domain the same way the DMARC step does.
        $dnsRoute = null === $focusDomain ? 'dashboard_domains' : 'dashboard_domain_health';
        $dnsRouteParams = null === $focusDomain
            ? []
            : ['id' => $focusDomain->id, '_fragment' => 'health-dmarc'];

        return match ($headlineDomainRuaScenario) {
            RuaScenario::PointsAtSendvery => new SetupChecklistStep(
                id: 'receive_reports',
                title: $title,
                description: 'Reports flow in automatically. The first one usually arrives within 24-48 hours of rua= publishing — Gmail, Outlook and Yahoo each send one per day per domain.',
                actionRoute: $dnsRoute,
                actionLabel: 'Check DNS setup',
                actionRouteParams: $dnsRouteParams,
                isComplete: $isComplete,
            ),
            RuaScenario::PointsAtExternal => new SetupChecklistStep(
                id: 'receive_reports',
                title: $title,
                description: 'Your DMARC record routes reports to an inbox you own. Connect that inbox so Sendvery can poll it — or repoint DMARC at Sendvery instead.',
                actionRoute: 'dashboard_mailbox_add',
                actionLabel: 'Connect that inbox',
                actionRouteParams: [],
                isComplete: $isComplete,
            ),
            RuaScenario::NoRecord, null => new SetupChecklistStep(
                id: 'receive_reports',
                title: $title,
                description: 'Reports flow in automatically once DMARC is published. Connect a mailbox if you prefer pulling them yourself.',
                actionRoute: $dnsRoute,
                actionLabel: 'Do it',
                actionRouteParams: $dnsRouteParams,
                isComplete: $isComplete,
            ),
        };
    }
}
