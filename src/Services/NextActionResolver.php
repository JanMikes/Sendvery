<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\Dns\RuaScenarioResult;
use App\Results\DomainIngestionMatrixResult;
use App\Results\DomainOverviewResult;
use App\Results\DomainVerificationStatusResult;
use App\Results\NextActionResult;
use App\Value\Dns\RuaScenario;
use App\Value\DomainVerificationSeverity;
use App\Value\IngestionPath;
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
 *  5. No central-inbox reports → PublishRuaRecord (DNS-first; TASK-091),
 *                                ConnectMailbox (fallback after 7 days OR
 *                                explicit dismissal)
 *  6. Default                 → AllHealthy
 */
final readonly class NextActionResolver
{
    /**
     * @param array<DomainOverviewResult>       $domains
     * @param list<DomainIngestionMatrixResult> $ingestionPaths
     */
    public function resolve(
        array $domains,
        ?DomainVerificationStatusResult $verificationStatus,
        ?DomainVerificationSeverity $verificationSeverity,
        int $unreadCriticalAlertCount,
        int $quarantineCount,
        bool $hasMailbox,
        string $reportAddress,
        ?\DateTimeImmutable $earliestDomainAddedAt,
        array $ingestionPaths,
        ?\DateTimeImmutable $ingestionRecommendationDismissedAt,
        \DateTimeImmutable $now,
        ?RuaScenarioResult $headlineDomainRuaScenario = null,
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
                description: sprintf(
                    'DMARC is published. Email providers send aggregate reports to %s daily — your first one should arrive within 48 hours.',
                    $reportAddress,
                ),
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

        // TASK-091: DNS-first ingestion guidance. The central inbox is the
        // recommended path — only fall back to "Connect a mailbox" after the
        // user has either explicitly dismissed the recommendation OR the
        // 7-day grace window has elapsed without any DNS-routed reports.
        $hasCentralInboxReports = false;
        foreach ($ingestionPaths as $row) {
            if (IngestionPath::Dns === $row->path || IngestionPath::Mixed === $row->path) {
                $hasCentralInboxReports = true;

                break;
            }
        }

        if (!$hasCentralInboxReports) {
            // TASK-100 scenario (b): DMARC already points at Sendvery — DNS
            // is doing the work. Skip the "publish RUA / connect a mailbox"
            // nudge entirely. But guard against TASK-102's lie: when no
            // central-inbox reports have arrived AND the team also hasn't
            // verified yet (verificationStatus null OR firstReportAt null),
            // saying "reports are flowing" would be false. Emit a
            // WaitForReports variant instead so the copy matches reality.
            if (RuaScenario::PointsAtSendvery === $headlineDomainRuaScenario?->scenario) {
                $firstReportAt = $verificationStatus?->firstReportAt;
                if (null === $firstReportAt) {
                    return new NextActionResult(
                        actionKey: NextAction::WaitForReports,
                        title: 'Waiting for your first report',
                        description: sprintf(
                            'DMARC is published and points at Sendvery. Email providers send aggregate reports to %s daily — your first one usually arrives within 24-48 hours.',
                            $reportAddress,
                        ),
                        ctaLabel: 'Check DNS setup',
                        ctaRoute: 'dashboard_dns_health',
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

            // TASK-100 scenario (c): DMARC publishes rua= pointing at the
            // team's own external inbox. Recommend connecting that inbox
            // OR repointing the DMARC record to Sendvery — equivalent paths.
            // Emitted unconditionally for this scenario (dismissal and the
            // 7-day timer are about the generic "no reports yet" fallback,
            // not about scenario-specific recommendations).
            if (RuaScenario::PointsAtExternal === $headlineDomainRuaScenario?->scenario) {
                $ruaEmail = $headlineDomainRuaScenario->ruaEmail ?? '';

                return new NextActionResult(
                    actionKey: NextAction::ConnectExternalMailbox,
                    title: sprintf('Connect the inbox at %s', $ruaEmail),
                    description: sprintf(
                        'Your DMARC record sends reports to %s. Connect that inbox so Sendvery can poll it for DMARC reports — or update the DMARC record to point at %s instead.',
                        $ruaEmail,
                        $reportAddress,
                    ),
                    ctaLabel: 'Connect this inbox',
                    ctaRoute: 'dashboard_mailbox_add',
                    ctaRouteParams: [],
                    severity: 'info',
                    secondaryCtaLabel: 'Or repoint DMARC to Sendvery',
                    secondaryCtaRoute: 'dashboard_dns_health',
                );
            }

            $sevenDaysPassed = null !== $earliestDomainAddedAt
                && $now > $earliestDomainAddedAt->modify('+7 days');
            $dismissed = null !== $ingestionRecommendationDismissedAt;

            if (!$dismissed && !$sevenDaysPassed) {
                return new NextActionResult(
                    actionKey: NextAction::PublishRuaRecord,
                    title: 'Publish a DMARC RUA record',
                    description: sprintf(
                        'Add a `_dmarc` TXT record with `rua=mailto:%s` to ingest reports without connecting a mailbox. Reports start flowing within 24 hours.',
                        $reportAddress,
                    ),
                    ctaLabel: 'How to publish RUA',
                    ctaRoute: 'dashboard_dns_health',
                    ctaRouteParams: [],
                    severity: 'info',
                    secondaryCtaLabel: 'Prefer to connect a mailbox instead? (fallback)',
                    secondaryCtaRoute: 'dashboard_mailbox_add',
                );
            }

            // Demoted fallback — only when DNS-based ingestion hasn't
            // produced anything after either an explicit user dismissal
            // or the 7-day grace window. Suppressed entirely once the
            // central inbox is delivering reports for any domain.
            return new NextActionResult(
                actionKey: NextAction::ConnectMailbox,
                title: 'Connect a mailbox (fallback)',
                description: "Reports aren't reaching Sendvery via DNS yet. Connect a mailbox where DMARC reports already arrive (e.g. `dmarc@yourcompany.com`) and we'll poll it every 5 minutes.",
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
