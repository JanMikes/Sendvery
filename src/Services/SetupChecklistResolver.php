<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\SetupChecklistResult;
use App\Value\Dns\RuaScenario;
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
    public function resolve(
        int $domainCount,
        bool $anyDomainHasDmarcVerified,
        bool $anyDomainHasFirstReport,
        bool $hasMailbox,
        ?\DateTimeImmutable $dismissedAt,
        bool $hasDmarcRegression,
        ?RuaScenario $headlineDomainRuaScenario,
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
            title: 'Publish your DMARC record',
            description: 'Add a DMARC TXT record so email receivers know where to send aggregate reports.',
            actionRoute: 'dashboard_domains',
            actionLabel: 'Do it',
            actionRouteParams: [],
            isComplete: $anyDomainHasDmarcVerified,
        );

        $receiveReportsStep = $this->buildReceiveReportsStep(
            anyDomainHasFirstReport: $anyDomainHasFirstReport,
            hasMailbox: $hasMailbox,
            headlineDomainRuaScenario: $headlineDomainRuaScenario,
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
    ): SetupChecklistStep {
        $isComplete = $anyDomainHasFirstReport || $hasMailbox;

        return match ($headlineDomainRuaScenario) {
            RuaScenario::PointsAtSendvery => new SetupChecklistStep(
                id: 'receive_reports',
                title: 'Receive your first DMARC report',
                description: 'Reports flow in automatically. The first one usually arrives within 24-48 hours of rua= publishing — Gmail, Outlook and Yahoo each send one per day per domain.',
                actionRoute: 'dashboard_domains',
                actionLabel: 'Check DNS setup',
                actionRouteParams: [],
                isComplete: $isComplete,
            ),
            RuaScenario::PointsAtExternal => new SetupChecklistStep(
                id: 'receive_reports',
                title: 'Receive your first DMARC report',
                description: 'Your DMARC record routes reports to an inbox you own. Connect that inbox so Sendvery can poll it — or repoint DMARC at Sendvery instead.',
                actionRoute: 'dashboard_mailbox_add',
                actionLabel: 'Connect that inbox',
                actionRouteParams: [],
                isComplete: $isComplete,
            ),
            RuaScenario::NoRecord, null => new SetupChecklistStep(
                id: 'receive_reports',
                title: 'Receive your first DMARC report',
                description: 'Reports flow in automatically once DMARC is published. Connect a mailbox if you prefer pulling them yourself.',
                actionRoute: 'dashboard_domains',
                actionLabel: 'Do it',
                actionRouteParams: [],
                isComplete: $isComplete,
            ),
        };
    }
}
