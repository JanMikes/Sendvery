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
 *  5. No central-inbox reports → scenario-aware nudges (TASK-100, TASK-102, TASK-129).
 *     Within this branch the per-team RUA-scenario priority is
 *     NoRecord > PointsAtExternal > PointsAtSendvery — the resolver inspects
 *     every domain in the team, not just the most-recently-added headline,
 *     so a single mis-configured domain in a multi-domain team still surfaces
 *     the right CTA.
 *  6. Default                 → AllHealthy
 */
final readonly class NextActionResolver
{
    /**
     * @param array<DomainOverviewResult>       $domains
     * @param list<DomainIngestionMatrixResult> $ingestionPaths
     * @param array<string, RuaScenarioResult>  $domainRuaScenarios per-domain
     *                                                              RUA-scenario classifications, keyed by `DomainOverviewResult::$domainId`.
     *                                                              TASK-129: lets the resolver pick the highest-attention scenario
     *                                                              across the team (priority: NoRecord > PointsAtExternal >
     *                                                              PointsAtSendvery) instead of relying solely on the LIMIT-1
     *                                                              headline domain. Falls back to `$headlineDomainRuaScenario` when
     *                                                              empty so older call sites keep working unchanged.
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
        array $domainRuaScenarios = [],
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
                ctaRoute: 'dashboard_domains',
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
            // TASK-129: pick the highest-attention RUA scenario across every
            // domain in the team — NoRecord beats PointsAtExternal beats
            // PointsAtSendvery. Falls back to the headline-only scenario when
            // the caller didn't supply per-domain data so older call sites
            // (and the unit tests that pre-date TASK-129) keep behaving the
            // way they did before.
            $teamScenario = $this->pickTeamScenario(
                domains: $domains,
                domainRuaScenarios: $domainRuaScenarios,
                headlineDomainRuaScenario: $headlineDomainRuaScenario,
            );

            // TASK-100 scenario (b): DMARC already points at Sendvery — DNS
            // is doing the work. Skip the "publish RUA / connect a mailbox"
            // nudge entirely. But guard against TASK-102's lie: when no
            // central-inbox reports have arrived AND no domain on this
            // scenario has a first report yet, saying "reports are flowing"
            // would be false. Emit a WaitForReports variant instead so the
            // copy matches reality.
            if (RuaScenario::PointsAtSendvery === $teamScenario?->scenario) {
                if ($this->anyPointsAtSendveryDomainHasNoReports(
                    domains: $domains,
                    domainRuaScenarios: $domainRuaScenarios,
                    verificationStatus: $verificationStatus,
                )) {
                    return new NextActionResult(
                        actionKey: NextAction::WaitForReports,
                        title: 'Waiting for your first report',
                        description: sprintf(
                            'DMARC is published and points at Sendvery. Email providers send aggregate reports to %s daily — your first one usually arrives within 24-48 hours.',
                            $reportAddress,
                        ),
                        ctaLabel: 'Check DNS setup',
                        ctaRoute: 'dashboard_domains',
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
            if (RuaScenario::PointsAtExternal === $teamScenario?->scenario) {
                $ruaEmail = $teamScenario->ruaEmail ?? '';

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
                    secondaryCtaRoute: 'dashboard_domains',
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
                    ctaRoute: 'dashboard_domains',
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

    /**
     * TASK-129: collapse the per-domain scenario map down to one "team
     * winner" using the priority order documented on the class
     * (NoRecord > PointsAtExternal > PointsAtSendvery). Returns the
     * winning {@see RuaScenarioResult} so the caller still has the
     * `ruaEmail` for the PointsAtExternal CTA copy.
     *
     * Callers that don't supply per-domain data (older tests, command
     * handlers, etc.) fall back to the single-domain headline scenario
     * so behaviour pre-TASK-129 stays bit-for-bit identical.
     *
     * @param array<DomainOverviewResult>      $domains
     * @param array<string, RuaScenarioResult> $domainRuaScenarios
     */
    private function pickTeamScenario(
        array $domains,
        array $domainRuaScenarios,
        ?RuaScenarioResult $headlineDomainRuaScenario,
    ): ?RuaScenarioResult {
        if ([] === $domainRuaScenarios) {
            return $headlineDomainRuaScenario;
        }

        $noRecord = null;
        $pointsAtExternal = null;
        $pointsAtSendvery = null;

        foreach ($domains as $domain) {
            $scenario = $domainRuaScenarios[$domain->domainId] ?? null;
            if (null === $scenario) {
                continue;
            }

            match ($scenario->scenario) {
                RuaScenario::NoRecord => $noRecord ??= $scenario,
                RuaScenario::PointsAtExternal => $pointsAtExternal ??= $scenario,
                RuaScenario::PointsAtSendvery => $pointsAtSendvery ??= $scenario,
            };
        }

        // NoRecord wins because a missing record blocks ingestion entirely
        // — fixing that is the only way the other domains' configurations
        // even start to matter.
        return $noRecord ?? $pointsAtExternal ?? $pointsAtSendvery ?? $headlineDomainRuaScenario;
    }

    /**
     * TASK-129: WaitForReports fires when ANY PointsAtSendvery domain in
     * the team has yet to receive its first report. We read
     * `DomainOverviewResult::firstReportAt` (sourced from the entity column)
     * rather than `totalReports` because totalReports collapses to 0 after
     * the nightly retention purge — a long-running domain would otherwise
     * regress into the "waiting for first report" copy. When per-domain data
     * is missing, fall back to the headline `verificationStatus->firstReportAt`
     * so the pre-129 single-domain shortcut keeps working.
     *
     * @param array<DomainOverviewResult>      $domains
     * @param array<string, RuaScenarioResult> $domainRuaScenarios
     */
    private function anyPointsAtSendveryDomainHasNoReports(
        array $domains,
        array $domainRuaScenarios,
        ?DomainVerificationStatusResult $verificationStatus,
    ): bool {
        if ([] === $domainRuaScenarios) {
            return null === $verificationStatus?->firstReportAt;
        }

        foreach ($domains as $domain) {
            $scenario = $domainRuaScenarios[$domain->domainId] ?? null;
            if (null === $scenario || RuaScenario::PointsAtSendvery !== $scenario->scenario) {
                continue;
            }

            if (null === $domain->firstReportAt) {
                return true;
            }
        }

        return false;
    }
}
