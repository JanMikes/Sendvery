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
 *    (30-day pass rate ≥ 90 OR no pass-rate data yet). "Configured" reads the
 *    newest stored `dns_check_result` row per protocol — the same authoritative
 *    source `DomainSetupStatusResolver` reads — falling back to the legacy
 *    verified-at / snapshot-score derivation only for protocols with no check row
 *    at all. See {@see self::protocolConfigured()} for why the timestamps alone
 *    were unsafe. The "no data yet" arm is deliberate — see
 *    {@see self::awaitingFirstReportVerdict()}.
 *  - Attention  ← any verified domain that isn't Healthy. Includes both the
 *    "missing a protocol" case (covers the green-on-list / yellow-on-detail
 *    bug for `DMARC verified + SPF missing + 95% pass`) and the
 *    "all configured but pass rate < 90" case (covers the yellow-on-list /
 *    green-on-detail bug). Also covers `verified + no DNS snapshot yet` —
 *    we don't claim Healthy until we've actually checked DNS.
 */
final readonly class DomainHealthClassifier
{
    /**
     * Public because {@see \App\Query\GetDomainOverview} has to express the very
     * same rule in SQL for the `?status=` filter, and the whole point of TASK-098
     * is that there is ONE threshold. Re-typing `90` / `80` into the query is how
     * the chip and the badge drifted apart in the first place.
     */
    public const float HEALTHY_PASS_RATE_THRESHOLD = 90.0;

    public const int MX_CONFIGURED_MIN_SCORE = 80;

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

        if (!$overview->hasPassRateData()) {
            return $this->awaitingFirstReportVerdict();
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

        if (!$overview->hasPassRateData()) {
            return $this->awaitingFirstReportVerdict();
        }

        if ($overview->passRate < self::HEALTHY_PASS_RATE_THRESHOLD) {
            return DomainHealthFilter::Attention;
        }

        return DomainHealthFilter::Healthy;
    }

    /**
     * Verdict for "DMARC verified, all four protocols configured, but we have
     * no pass-rate data yet" — i.e. a correctly set up domain still waiting for
     * its first DMARC report (reporters typically send one per UTC day, so this
     * state legitimately lasts up to ~24h after setup), or one whose reports
     * have aged out of the retention window.
     *
     * It is Healthy, not Attention. The domain's *setup* — the only thing this
     * classifier judges — is complete; there is simply nothing to grade yet.
     * Before this branch existed the missing data arrived as `passRate = 0.0`
     * and tripped the `< 90` check, so a brand-new, perfectly configured domain
     * was accused of failing every message: amber border, warning triangle, and
     * a red "0.0%" on the card.
     *
     * Deliberately NOT a fourth `DomainHealthFilter` case. The three cases map
     * 1:1 onto the three filter chips, the three glyph tones, and the three
     * `HealthSummaryResolver` buckets; a fourth would need a chip, a tone, a
     * bucket, and a matching SQL predicate everywhere for a state that is
     * transient by nature. The *presentation* of "no data yet" belongs to the
     * pass-rate widget (`pass_rate_stat` in `components/_severity_glyph.html.twig`,
     * driven by `DomainOverviewResult::$passRate` being null), not to the
     * setup-health verdict.
     */
    private function awaitingFirstReportVerdict(): DomainHealthFilter
    {
        return DomainHealthFilter::Healthy;
    }

    private function allProtocolsConfigured(DnsHealthOverviewResult $dnsHealth): bool
    {
        return $this->protocolConfigured($dnsHealth->spfCheckValid, null !== $dnsHealth->spfVerifiedAt)
            && $this->protocolConfigured($dnsHealth->dkimCheckValid, null !== $dnsHealth->dkimVerifiedAt)
            && $this->protocolConfigured($dnsHealth->dmarcCheckValid, null !== $dnsHealth->dmarcVerifiedAt)
            && $this->protocolConfigured(
                $dnsHealth->mxCheckValid,
                null !== $dnsHealth->latestMxScore && $dnsHealth->latestMxScore >= self::MX_CONFIGURED_MIN_SCORE,
            );
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
        return $this->protocolConfigured($overview->spfCheckValid, null !== $overview->spfVerifiedAt)
            && $this->protocolConfigured($overview->dkimCheckValid, null !== $overview->dkimVerifiedAt)
            && $this->protocolConfigured($overview->dmarcCheckValid, null !== $overview->dmarcVerifiedAt)
            && $this->protocolConfigured(
                $overview->mxCheckValid,
                null !== $overview->latestMxScore && $overview->latestMxScore >= self::MX_CONFIGURED_MIN_SCORE,
            );
    }

    /**
     * Is one protocol configured? The stored `dns_check_result` verdict WINS
     * whenever one exists, in both directions:
     *
     *  - `$checkValid === true`  → configured, even with no verified-at column
     *    and no snapshot score (the MX case, and any domain checked before the
     *    nightly sweep has run).
     *  - `$checkValid === false` → NOT configured, even though `*_verified_at` is
     *    still set. This is the whole point: `CheckDomainDnsHandler` only ever
     *    SETS those timestamps and never clears them, so a record that broke last
     *    month keeps a verified-at from when it last worked. Reading the timestamp
     *    gave a domain with dead SPF the green "fully healthy" chip AND dropped it
     *    out of "Needs your attention" — the alert email said one thing and the
     *    triage surface said nothing at all.
     *  - `$checkValid === null`  → no check row for this protocol. Fall back to
     *    the legacy derivation rather than assume anything; "we have not looked"
     *    is not "it is broken", and it is not "it is fine" either.
     *
     * Same precedence as {@see DomainSetupStatusResolver::stateFor()},
     * deliberately — the banner's per-protocol checklist and this severity verdict
     * must not read the same domain from two different sources.
     */
    private function protocolConfigured(?bool $checkValid, bool $legacyConfigured): bool
    {
        return $checkValid ?? $legacyConfigured;
    }
}
