<?php

declare(strict_types=1);

namespace App\Value\Reports;

enum QuarantineReason: string
{
    /** No team has this domain in monitored_domain at all. */
    case UnknownDomain = 'unknown_domain';

    /** Domain exists in monitored_domain but no team has verified it yet. */
    case UnverifiedDomain = 'unverified_domain';

    /**
     * Team has hit its monthly report cap (`PlanLimits::getMaxReportsPerMonth`).
     * Per `never-delete-user-data`, over-cap reports are queued instead of
     * dropped; users can revisit on upgrade.
     */
    case PlanOverage = 'plan_overage';

    /**
     * Maps each reason to the daisyUI v5 severity token used by the
     * leading-glyph row treatment on `/app/quarantine` (TASK-071). The three
     * reasons map to very different next-actions for the user — a paid
     * `plan_overage` row should look red/urgent, an in-progress
     * `unverified_domain` row amber, and an informational `unknown_domain`
     * row blue. Living on the enum keeps the rule the single source of
     * truth so templates don't redrift the mapping.
     *
     * @return 'error'|'warning'|'info'
     */
    public function severityTone(): string
    {
        return match ($this) {
            self::PlanOverage => 'error',
            self::UnverifiedDomain => 'warning',
            self::UnknownDomain => 'info',
        };
    }
}
