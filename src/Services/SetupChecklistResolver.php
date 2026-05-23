<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\SetupChecklistResult;
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

        $receiveReportsStep = new SetupChecklistStep(
            id: 'receive_reports',
            title: 'Receive your first DMARC report',
            description: 'Reports flow in automatically once DMARC is published. Connect a mailbox if you prefer pulling them yourself.',
            actionRoute: 'dashboard_dns_health',
            actionLabel: 'Do it',
            actionRouteParams: [],
            isComplete: $anyDomainHasFirstReport || $hasMailbox,
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
}
