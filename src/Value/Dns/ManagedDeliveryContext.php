<?php

declare(strict_types=1);

namespace App\Value\Dns;

use App\Value\SubscriptionPlan;

/**
 * Everything the guided setup surface needs to know about the managed-DMARC
 * path, resolved once from infrastructure and entitlement state.
 *
 * WHY this is a value object rather than three services injected into
 * {@see \App\Services\Dns\GuidedDnsSetupResolver}: the resolver's job is
 * deciding what to tell the user, and it should be a pure function of its
 * inputs. Asking a Cloudflare client and a Stripe plan enforcer questions
 * mid-decision would drag HTTP config and a database connection into the one
 * class whose logic most needs to be exhaustively testable.
 */
final readonly class ManagedDeliveryContext
{
    /**
     * @param bool             $dnsAutomationConfigured this installation has a DNS provider connected at all
     * @param bool             $managedAvailable        the team may actually switch this domain to the managed path
     * @param SubscriptionPlan $nextTier                upgrade target named in the upsell, null at the top tier
     * @param string|null      $cnameTarget             the CNAME value to publish, null when we cannot host records
     */
    public function __construct(
        public bool $dnsAutomationConfigured,
        public bool $managedAvailable,
        public ?SubscriptionPlan $nextTier,
        public ?string $cnameTarget,
    ) {
    }

    /**
     * The neutral context for an installation that cannot host DMARC records at
     * all — a self-hoster who never configured a DNS provider. The managed
     * option is still shown, with this as the reason it is inert.
     */
    public static function unavailable(): self
    {
        return new self(
            dnsAutomationConfigured: false,
            managedAvailable: false,
            nextTier: null,
            cnameTarget: null,
        );
    }
}
