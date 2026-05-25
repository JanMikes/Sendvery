<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\DnsHealthOverviewResult;
use App\Results\DomainOverviewResult;
use App\Value\DomainHealthFilter;

/**
 * Single source of truth for "is this domain set up correctly?" — replaces
 * the two divergent classifiers TASK-098 removed:
 *
 *  - `DomainHealthFilter::fromOverview()` (list page, 2 inputs: DMARC
 *     verified + 30-day pass rate).
 *  - `DomainSetupStatusResolver` (detail page, 4 inputs: per-protocol
 *     SPF/DKIM/DMARC/MX states from DNS health snapshot).
 *
 * The same domain now renders the same color + verdict on `/app` summary,
 * `/app/domains` cards, and `/app/domains/{id}` banner.
 *
 * Unified rule:
 *  - Unverified ← DMARC not verified (no reports flow yet → the headline
 *    blocker no matter what else is going on).
 *  - Healthy    ← DMARC verified AND all 4 DNS protocols configured AND
 *    30-day pass rate ≥ 90. "Configured" mirrors the `DomainSetupStatusResolver`
 *    thresholds: SPF/DKIM/DMARC verified-at present, MX score ≥ 80.
 *  - Attention  ← any verified domain that isn't Healthy. Includes both the
 *    "missing a protocol" case (covers the green-on-list / yellow-on-detail
 *    bug for `DMARC verified + SPF missing + 95% pass`) and the
 *    "all configured but pass rate < 90" case (covers the yellow-on-list /
 *    green-on-detail bug). Also covers `verified + no DNS snapshot yet` —
 *    we don't claim Healthy until we've actually checked DNS.
 */
final readonly class DomainHealthClassifier
{
    private const float HEALTHY_PASS_RATE_THRESHOLD = 90.0;
    private const int MX_CONFIGURED_MIN_SCORE = 80;

    /**
     * Two-input classifier: takes a `DomainOverviewResult` (carries
     * DMARC-verified flag + pass rate) and an optional `DnsHealthOverviewResult`
     * (carries per-protocol verified-at + latest MX score).
     *
     * When `$dnsHealth` is null the classifier can't prove "all 4 protocols
     * configured" — it falls into Attention for any verified domain, or
     * Unverified for any unverified one. This is the conservative branch:
     * we'd rather under-state Healthy than claim "all good" on incomplete data.
     */
    public function classify(DomainOverviewResult $overview, ?DnsHealthOverviewResult $dnsHealth = null): DomainHealthFilter
    {
        if (null === $overview->dmarcVerifiedAt) {
            return DomainHealthFilter::Unverified;
        }

        if (null === $dnsHealth || !$this->allProtocolsConfigured($dnsHealth)) {
            return DomainHealthFilter::Attention;
        }

        if ($overview->passRate < self::HEALTHY_PASS_RATE_THRESHOLD) {
            return DomainHealthFilter::Attention;
        }

        return DomainHealthFilter::Healthy;
    }

    /**
     * Single-input convenience for callers that already have the joined-in
     * DNS-snapshot fields on the overview row (post-TASK-098, `GetDomainOverview`
     * carries them). Behaviourally identical to {@see classify()} when fed the
     * same data — the regression invariant test asserts the parity.
     *
     * This is what `ListDomainsController` calls per row to drive the
     * `DomainCard` glyph: zero extra queries, classification fed entirely from
     * the columns the list query already selects.
     */
    public function classifyOverview(DomainOverviewResult $overview): DomainHealthFilter
    {
        if (null === $overview->dmarcVerifiedAt) {
            return DomainHealthFilter::Unverified;
        }

        if (!$this->allProtocolsConfiguredFromOverview($overview)) {
            return DomainHealthFilter::Attention;
        }

        if ($overview->passRate < self::HEALTHY_PASS_RATE_THRESHOLD) {
            return DomainHealthFilter::Attention;
        }

        return DomainHealthFilter::Healthy;
    }

    private function allProtocolsConfigured(DnsHealthOverviewResult $dnsHealth): bool
    {
        return $dnsHealth->isSpfVerified()
            && $dnsHealth->isDkimVerified()
            && $dnsHealth->isDmarcVerified()
            && null !== $dnsHealth->latestMxScore
            && $dnsHealth->latestMxScore >= self::MX_CONFIGURED_MIN_SCORE;
    }

    /**
     * Public wrapper around {@see allProtocolsConfigured()} so callers that
     * already have a {@see DnsHealthOverviewResult} (the DNS Health overview
     * page, TASK-083) can ask the same "is everything in place?" question
     * without re-deriving the rule. Keeps the per-protocol threshold in one
     * place per TASK-098.
     */
    public function isFullyHealthy(DnsHealthOverviewResult $dnsHealth): bool
    {
        return $this->allProtocolsConfigured($dnsHealth);
    }

    /**
     * Mirror of {@see allProtocolsConfigured()} for the joined-in shape on
     * `DomainOverviewResult`. Distinct method (rather than a synthetic
     * `DnsHealthOverviewResult` build) because the overview row carries only
     * the columns the classifier needs — no `latestSnapshotGrade`, no
     * `latestCheckedAt` — and faking a full DTO would invite drift.
     */
    private function allProtocolsConfiguredFromOverview(DomainOverviewResult $overview): bool
    {
        return null !== $overview->spfVerifiedAt
            && null !== $overview->dkimVerifiedAt
            && null !== $overview->dmarcVerifiedAt
            && null !== $overview->latestMxScore
            && $overview->latestMxScore >= self::MX_CONFIGURED_MIN_SCORE;
    }
}
