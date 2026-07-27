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
     * Whether the `sendvery:reports:quarantine:purge` cron may delete a row
     * with this reason once `expires_at` has passed.
     *
     * `plan_overage` says NO, and that is load-bearing: those reports are a
     * paying customer's own data, withheld only because their plan ran out of
     * monthly headroom. Per `never-delete-user-data` a cap causes freeze, never
     * deletion — they are released when the team upgrades or when the monthly
     * period rolls (see ReleaseQuarantinedReportsForTeamHandler), so a TTL is
     * the wrong lifecycle for them entirely. The other two reasons hold reports
     * for domains nobody ever proved ownership of; without a TTL that table
     * grows forever on mail we can never hand to anyone.
     */
    public function isTtlPurgeable(): bool
    {
        return match ($this) {
            self::PlanOverage => false,
            self::UnknownDomain, self::UnverifiedDomain => true,
        };
    }

    /**
     * The reasons the TTL purge is allowed to delete, for query filters.
     *
     * Derived from {@see isTtlPurgeable()} rather than hard-coded so a new
     * reason cannot be silently purgeable — it has to answer the question on
     * the enum, where the rule lives.
     *
     * @return list<self>
     */
    public static function ttlPurgeable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $reason): bool => $reason->isTtlPurgeable(),
        ));
    }

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
