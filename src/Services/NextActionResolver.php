<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\DomainOverviewResult;
use App\Results\DomainVerificationStatusResult;
use App\Results\NextActionResult;
use App\Value\DomainVerificationSeverity;
use App\Value\NextAction;

/**
 * Picks the single highest-value next step for the dashboard overview based
 * on already-fetched team state. Pure computation: no DB, no clock — the
 * caller assembles inputs, this picks the priority winner.
 *
 * Priority chain, most urgent first:
 *  1. No domains              → AddDomain    (can't do anything else)
 *  2. DMARC verification Critical → VerifyDns (alerts are noise without DMARC)
 *  3. DMARC Warning / Info    → WaitForReports
 *  4. Unread critical alerts  → ReviewAlerts
 *  5. No mailbox + no reports → ConnectMailbox (suppressed if any reports flow)
 *  6. Default                 → AllHealthy
 */
final readonly class NextActionResolver
{
    /**
     * @param array<DomainOverviewResult> $domains
     */
    public function resolve(
        array $domains,
        ?DomainVerificationStatusResult $verificationStatus,
        ?DomainVerificationSeverity $verificationSeverity,
        int $unreadCriticalAlertCount,
        int $quarantineCount,
        bool $hasMailbox,
    ): NextActionResult {
        if (0 === count($domains)) {
            return new NextActionResult(
                actionKey: NextAction::AddDomain,
                title: 'Add your first domain',
                description: 'Sendvery monitors DMARC reports delivered to your domains. Add a domain to get started.',
                ctaLabel: 'Add domain',
                ctaRoute: 'dashboard_domain_add',
                ctaRouteParams: [],
                severity: 'error',
            );
        }

        if (DomainVerificationSeverity::Critical === $verificationSeverity && null !== $verificationStatus) {
            return new NextActionResult(
                actionKey: NextAction::VerifyDns,
                title: sprintf('Verify DNS for %s', $verificationStatus->domainName),
                description: sprintf(
                    'Publish your DMARC TXT record for %s so reports can be delivered. We re-check every day, or you can trigger a check now.',
                    $verificationStatus->domainName,
                ),
                ctaLabel: 'Re-check DNS',
                ctaRoute: 'dashboard_domain_reverify',
                ctaRouteParams: ['id' => $verificationStatus->domainId],
                severity: 'error',
            );
        }

        if (DomainVerificationSeverity::Warning === $verificationSeverity
            || DomainVerificationSeverity::Info === $verificationSeverity
        ) {
            return new NextActionResult(
                actionKey: NextAction::WaitForReports,
                title: 'Waiting for your first report',
                description: 'DMARC is set up correctly. Email receivers send aggregate reports daily — your first one should arrive within 48 hours.',
                ctaLabel: 'Check DNS setup',
                ctaRoute: 'dashboard_dns_health',
                ctaRouteParams: [],
                severity: 'warning',
            );
        }

        if ($unreadCriticalAlertCount > 0) {
            return new NextActionResult(
                actionKey: NextAction::ReviewAlerts,
                title: sprintf(
                    'Review %d critical alert%s',
                    $unreadCriticalAlertCount,
                    1 === $unreadCriticalAlertCount ? '' : 's',
                ),
                description: 'You have unread critical alerts that may indicate spoofing or misconfiguration.',
                ctaLabel: 'View alerts',
                ctaRoute: 'dashboard_alerts',
                ctaRouteParams: [],
                severity: 'error',
            );
        }

        // Central inbox already delivering reports → connecting a personal mailbox
        // is no longer the top priority. Only nudge ConnectMailbox when *every*
        // domain has zero reports.
        $allDomainsWithoutReports = array_reduce(
            $domains,
            static fn (bool $carry, DomainOverviewResult $domain): bool => $carry && 0 === $domain->totalReports,
            true,
        );

        if (!$hasMailbox && $allDomainsWithoutReports) {
            return new NextActionResult(
                actionKey: NextAction::ConnectMailbox,
                title: 'Connect a mailbox',
                description: "Connect a dedicated IMAP mailbox to receive DMARC reports directly, in addition to Sendvery's central inbox.",
                ctaLabel: 'Connect mailbox',
                ctaRoute: 'dashboard_mailbox_add',
                ctaRouteParams: [],
                severity: 'info',
            );
        }

        return new NextActionResult(
            actionKey: NextAction::AllHealthy,
            title: 'Everything looks good',
            description: 'All your domains are healthy and reports are flowing. Keep an eye on your alerts.',
            ctaLabel: 'View reports',
            ctaRoute: 'dashboard_reports',
            ctaRouteParams: [],
            severity: 'success',
        );
    }
}
