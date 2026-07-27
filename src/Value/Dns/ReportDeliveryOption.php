<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * One of the two ways Sendvery can receive a domain's DMARC reports, rendered
 * side by side so the choice is visible instead of implied.
 *
 * WHY both are always rendered: `/app/domains/{id}/health` used to offer the
 * self-managed TXT record and nothing else, so a user on a paid plan had no way
 * to discover the managed-CNAME path from the page that was asking them to edit
 * DNS ("what about the CNAME option for premium — it does not allow me here at
 * all"). Hiding an unavailable option is worse than showing it greyed out with
 * the reason: the user cannot act on information they never see.
 */
final readonly class ReportDeliveryOption
{
    /**
     * @param bool        $selected          the path this domain is on today
     * @param bool        $available         false when the plan or the installation cannot use it — render as an upsell, never hide
     * @param string|null $unavailableReason why it cannot be used, shown in place of the action
     * @param string|null $upgradeRoute      route name for the upgrade CTA, null when upgrading is not the fix
     * @param string|null $switchRoute       route name that moves the domain onto this path, null when it is already there or unavailable
     */
    public function __construct(
        public DmarcSetupMode $mode,
        public string $label,
        public string $summary,
        public bool $selected,
        public bool $available,
        public bool $isPremium,
        public ?string $unavailableReason,
        public ?string $upgradeRoute,
        public ?string $switchRoute,
        public ?string $switchLabel,
        public ?string $csrfTokenId,
    ) {
    }
}
