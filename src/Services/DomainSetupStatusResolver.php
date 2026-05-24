<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\DnsHealthOverviewResult;
use App\Results\DomainSetupStatus;
use App\Results\ProtocolSetupStatus;
use App\Value\DomainHealthFilter;
use App\Value\DomainSetupDisplayMode;
use App\Value\ProtocolState;

/**
 * Translates the raw DNS-health snapshot into the structured verdict the
 * domain detail page renders as: a one-line status banner (TASK-067) and an
 * expanded 4-row setup checklist (TASK-080).
 *
 * All per-protocol state is derived from the columns already on
 * {@see DnsHealthOverviewResult} (verified-at timestamps + latest score) — no
 * new query needed. The resolver owns ALL copy strings so the Twig components
 * stay props-only renderers.
 */
final readonly class DomainSetupStatusResolver
{
    /**
     * MX score >= 80 = "configured" — matches the existing badge threshold
     * (templates/dashboard/domain_detail.html.twig pre-refactor) and the
     * homepage MxChecker's "looks good" cutoff. Below 80 (with a score) means
     * we got an answer from DNS but it didn't look right — distinct from
     * "no answer at all" (score === null).
     */
    private const int MX_CONFIGURED_MIN_SCORE = 80;

    public function resolve(?DnsHealthOverviewResult $dnsHealth): DomainSetupStatus
    {
        // Two paths collapse to "no DNS check has run yet": (a) the query
        // returned null (cross-tenant guard), (b) the query returned a row
        // but the DNS cron hasn't recorded a verification timestamp OR a
        // snapshot score yet. Treat the second case as the all-Unknown
        // pending state too — otherwise a freshly-added domain renders
        // straight into the "Setup incomplete" branch with four red Missing
        // rows before any check has actually run.
        if (null === $dnsHealth || $this->isUnchecked($dnsHealth)) {
            // No snapshot yet — most often a brand-new domain whose first DNS
            // cron run hasn't completed. The headline tells the user where to
            // start; the four checklist rows render as Unknown.
            $protocols = [
                new ProtocolSetupStatus(
                    name: 'SPF',
                    state: ProtocolState::Unknown,
                    statusLine: 'No DNS check yet',
                    nextStep: null,
                    kbSlug: null,
                    healthAnchor: 'health-spf',
                ),
                new ProtocolSetupStatus(
                    name: 'DKIM',
                    state: ProtocolState::Unknown,
                    statusLine: 'No DNS check yet',
                    nextStep: null,
                    kbSlug: null,
                    healthAnchor: 'health-dkim',
                ),
                new ProtocolSetupStatus(
                    name: 'DMARC',
                    state: ProtocolState::Unknown,
                    statusLine: 'No DNS check yet',
                    nextStep: null,
                    kbSlug: null,
                    healthAnchor: 'health-dmarc',
                ),
                new ProtocolSetupStatus(
                    name: 'MX',
                    state: ProtocolState::Unknown,
                    statusLine: 'No DNS check yet',
                    nextStep: null,
                    kbSlug: null,
                    healthAnchor: 'health-mx',
                ),
            ];

            // PanelOnly: the banner is hidden in this state — the old
            // "DNS not configured yet" headline was a wrong-information bug
            // (we hadn't actually checked yet) and the panel's info-blue
            // "We haven't checked DNS yet" panel leads alone (TASK-097).
            // The headline/CTA are still populated so the DTO stays
            // sensible for snapshot tests and standalone uses, but no
            // template reads them in this state.
            return new DomainSetupStatus(
                severity: DomainHealthFilter::Unverified,
                headline: 'DNS not configured yet — start with the SPF record',
                ctaLabel: 'Set up SPF',
                ctaRoute: 'dashboard_domain_health',
                ctaFragment: 'health-spf',
                protocols: $protocols,
                displayMode: DomainSetupDisplayMode::PanelOnly,
            );
        }

        $spf = $this->buildSpf($dnsHealth);
        $dkim = $this->buildDkim($dnsHealth);
        $dmarc = $this->buildDmarc($dnsHealth);
        $mx = $this->buildMx($dnsHealth);

        $protocols = [$spf, $dkim, $dmarc, $mx];

        $allConfigured = ProtocolState::Configured === $spf->state
            && ProtocolState::Configured === $dkim->state
            && ProtocolState::Configured === $dmarc->state
            && ProtocolState::Configured === $mx->state;

        if ($allConfigured) {
            // BannerOnly: the one-line "Monitoring active" headline is
            // enough — the redundant "DNS setup is complete" panel below it
            // would just repeat the same news a second time (TASK-097).
            return new DomainSetupStatus(
                severity: DomainHealthFilter::Healthy,
                headline: 'Monitoring active — all four records are in place',
                ctaLabel: null,
                ctaRoute: null,
                ctaFragment: null,
                protocols: $protocols,
                displayMode: DomainSetupDisplayMode::BannerOnly,
            );
        }

        // Unverified beats Attention: until DMARC is verified, the page can't
        // collect reports, so that's the dominant blocker regardless of
        // anything else's state.
        if (ProtocolState::Configured !== $dmarc->state) {
            return new DomainSetupStatus(
                severity: DomainHealthFilter::Unverified,
                headline: 'Setup incomplete — DMARC record not yet published',
                ctaLabel: 'Set up DMARC',
                ctaRoute: 'dashboard_domain_health',
                ctaFragment: 'health-dmarc',
                protocols: $protocols,
                displayMode: DomainSetupDisplayMode::BannerAndPanel,
            );
        }

        // DMARC OK — Attention with most-urgent CTA picked by DMARC > SPF >
        // DKIM > MX precedence (DMARC already excluded by the branch above,
        // so the practical ordering is SPF > DKIM > MX).
        $failingNames = array_values(array_map(
            static fn (ProtocolSetupStatus $p): string => $p->name,
            array_filter(
                $protocols,
                static fn (ProtocolSetupStatus $p): bool => ProtocolState::Configured !== $p->state,
            ),
        ));

        // DMARC is guaranteed Configured here (the Unverified branch above
        // returned for anything else), so the practical ordering for the most
        // urgent CTA is SPF > DKIM > MX.
        $ctaFragment = match (true) {
            ProtocolState::Configured !== $spf->state => 'health-spf',
            ProtocolState::Configured !== $dkim->state => 'health-dkim',
            default => 'health-mx',
        };

        return new DomainSetupStatus(
            severity: DomainHealthFilter::Attention,
            headline: sprintf('Action needed — %s', implode(', ', $failingNames)),
            ctaLabel: 'Fix DNS records',
            ctaRoute: 'dashboard_domain_health',
            ctaFragment: $ctaFragment,
            protocols: $protocols,
            displayMode: DomainSetupDisplayMode::BannerAndPanel,
        );
    }

    /**
     * "Nothing has been checked yet" — no verification timestamp on ANY
     * record and no scored snapshot. Differs from "checked and failing" in
     * that we don't want to surface red Missing rows before the very first
     * check has run.
     */
    private function isUnchecked(DnsHealthOverviewResult $dnsHealth): bool
    {
        return !$dnsHealth->isSpfVerified()
            && !$dnsHealth->isDkimVerified()
            && !$dnsHealth->isDmarcVerified()
            && null === $dnsHealth->latestSpfScore
            && null === $dnsHealth->latestDkimScore
            && null === $dnsHealth->latestDmarcScore
            && null === $dnsHealth->latestMxScore;
    }

    private function buildSpf(DnsHealthOverviewResult $dnsHealth): ProtocolSetupStatus
    {
        if ($dnsHealth->isSpfVerified()) {
            return new ProtocolSetupStatus(
                name: 'SPF',
                state: ProtocolState::Configured,
                statusLine: 'SPF record published and aligned',
                nextStep: null,
                kbSlug: null,
                healthAnchor: 'health-spf',
            );
        }

        if (null === $dnsHealth->latestSpfScore) {
            return new ProtocolSetupStatus(
                name: 'SPF',
                state: ProtocolState::Missing,
                statusLine: 'SPF record not detected',
                nextStep: 'Publish a TXT record starting with `v=spf1`',
                kbSlug: 'spf-record-syntax',
                healthAnchor: 'health-spf',
            );
        }

        return new ProtocolSetupStatus(
            name: 'SPF',
            state: ProtocolState::Invalid,
            statusLine: 'SPF record present but failing checks',
            nextStep: 'Fix the SPF record syntax',
            kbSlug: 'spf-record-syntax',
            healthAnchor: 'health-spf',
        );
    }

    private function buildDkim(DnsHealthOverviewResult $dnsHealth): ProtocolSetupStatus
    {
        if ($dnsHealth->isDkimVerified()) {
            return new ProtocolSetupStatus(
                name: 'DKIM',
                state: ProtocolState::Configured,
                statusLine: 'DKIM key published and aligned',
                nextStep: null,
                kbSlug: null,
                healthAnchor: 'health-dkim',
            );
        }

        if (null === $dnsHealth->latestDkimScore) {
            return new ProtocolSetupStatus(
                name: 'DKIM',
                state: ProtocolState::Missing,
                statusLine: 'DKIM key not detected',
                nextStep: "Add a CNAME or TXT record at your mail provider's selector",
                kbSlug: 'dkim-setup-guide',
                healthAnchor: 'health-dkim',
            );
        }

        return new ProtocolSetupStatus(
            name: 'DKIM',
            state: ProtocolState::Invalid,
            statusLine: 'DKIM key present but failing checks',
            nextStep: 'Renew or fix the DKIM key',
            kbSlug: 'dkim-setup-guide',
            healthAnchor: 'health-dkim',
        );
    }

    private function buildDmarc(DnsHealthOverviewResult $dnsHealth): ProtocolSetupStatus
    {
        if ($dnsHealth->isDmarcVerified()) {
            return new ProtocolSetupStatus(
                name: 'DMARC',
                state: ProtocolState::Configured,
                statusLine: 'DMARC TXT record published',
                nextStep: null,
                kbSlug: null,
                healthAnchor: 'health-dmarc',
            );
        }

        if (null === $dnsHealth->latestDmarcScore) {
            return new ProtocolSetupStatus(
                name: 'DMARC',
                state: ProtocolState::Missing,
                statusLine: 'DMARC TXT record not detected',
                nextStep: 'Publish a `_dmarc` TXT record with `rua=mailto:reports@sendvery.com`',
                kbSlug: 'dmarc-quick-start',
                healthAnchor: 'health-dmarc',
            );
        }

        return new ProtocolSetupStatus(
            name: 'DMARC',
            state: ProtocolState::Invalid,
            statusLine: 'DMARC TXT record present but failing checks',
            nextStep: 'Fix the DMARC record syntax',
            kbSlug: 'dmarc-quick-start',
            healthAnchor: 'health-dmarc',
        );
    }

    private function buildMx(DnsHealthOverviewResult $dnsHealth): ProtocolSetupStatus
    {
        if (null !== $dnsHealth->latestMxScore && $dnsHealth->latestMxScore >= self::MX_CONFIGURED_MIN_SCORE) {
            return new ProtocolSetupStatus(
                name: 'MX',
                state: ProtocolState::Configured,
                statusLine: 'MX records resolve to your mail provider',
                nextStep: null,
                kbSlug: null,
                healthAnchor: 'health-mx',
            );
        }

        if (null === $dnsHealth->latestMxScore) {
            return new ProtocolSetupStatus(
                name: 'MX',
                state: ProtocolState::Missing,
                statusLine: 'MX records not detected',
                nextStep: 'Add MX records for your mail provider',
                kbSlug: 'mx-records-explained',
                healthAnchor: 'health-mx',
            );
        }

        return new ProtocolSetupStatus(
            name: 'MX',
            state: ProtocolState::Invalid,
            statusLine: 'MX records present but failing checks',
            nextStep: 'Check MX records with your DNS provider',
            kbSlug: 'mx-records-explained',
            healthAnchor: 'health-mx',
        );
    }
}
