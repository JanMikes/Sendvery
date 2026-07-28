<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\DnsHealthOverviewResult;
use App\Results\DomainOverviewResult;
use App\Services\DomainHealthClassifier;
use App\Value\DomainHealthFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TASK-098 — load-bearing unit suite for the unified severity calculator
 * that replaces the two pre-existing per-surface classifiers. Every branch
 * the matrix can take is covered; an extra parity test asserts that
 * `classify()` and `classifyOverview()` agree for the same domain, which
 * locks the bug we're fixing (banner and list-card no longer disagree).
 */
final class DomainHealthClassifierTest extends TestCase
{
    #[Test]
    public function unverifiedWhenDmarcVerifiedAtIsNullEvenWithGreatPassRate(): void
    {
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: null, passRate: 100.0),
            dnsHealth: $this->dnsHealthAllConfigured(),
        );

        self::assertSame(DomainHealthFilter::Unverified, $severity);
    }

    #[Test]
    public function unverifiedWhenDmarcVerifiedAtIsNullAndDnsHealthIsNull(): void
    {
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: null, passRate: 0.0),
            dnsHealth: null,
        );

        self::assertSame(DomainHealthFilter::Unverified, $severity);
    }

    #[Test]
    public function healthyWhenVerifiedAllProtocolsConfiguredAndPassRateAtBoundary(): void
    {
        // 90.0 is the boundary — `>=` semantics, so it counts as Healthy.
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 90.0),
            dnsHealth: $this->dnsHealthAllConfigured(),
        );

        self::assertSame(DomainHealthFilter::Healthy, $severity);
    }

    #[Test]
    public function healthyWhenVerifiedAllProtocolsConfiguredAndPassRateWellAbove90(): void
    {
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 99.9),
            dnsHealth: $this->dnsHealthAllConfigured(),
        );

        self::assertSame(DomainHealthFilter::Healthy, $severity);
    }

    #[Test]
    public function attentionWhenVerifiedAllProtocolsConfiguredButPassRateBelow90(): void
    {
        // Locks the bug: list page used to render this green
        // (DMARC verified + passRate >= 90), detail page red — now both yellow.
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 89.9),
            dnsHealth: $this->dnsHealthAllConfigured(),
        );

        self::assertSame(DomainHealthFilter::Attention, $severity);
    }

    #[Test]
    public function attentionWhenVerifiedAndPassRateOkButSpfIsMissing(): void
    {
        // The other half of the same bug — list used to call this Healthy
        // (DMARC verified + 95% pass), detail called it Attention (SPF
        // missing). Now both yellow.
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 95.0),
            dnsHealth: $this->dnsHealthAllConfigured(spfVerifiedAt: null),
        );

        self::assertSame(DomainHealthFilter::Attention, $severity);
    }

    #[Test]
    public function attentionWhenVerifiedAndPassRateOkButDkimIsMissing(): void
    {
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 95.0),
            dnsHealth: $this->dnsHealthAllConfigured(dkimVerifiedAt: null),
        );

        self::assertSame(DomainHealthFilter::Attention, $severity);
    }

    #[Test]
    public function attentionWhenVerifiedAndPassRateOkButDmarcDnsHealthNotVerified(): void
    {
        // Edge case: `overview.dmarcVerifiedAt` is set (the SQL column on
        // `monitored_domain`) but the latest DNS snapshot reports a
        // non-verified DMARC record. We trust the DNS snapshot for the
        // "is it set up right now?" check.
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 95.0),
            dnsHealth: $this->dnsHealthAllConfigured(dmarcVerifiedAt: null),
        );

        self::assertSame(DomainHealthFilter::Attention, $severity);
    }

    #[Test]
    public function attentionWhenVerifiedAndPassRateOkButMxScoreBelow80(): void
    {
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 95.0),
            dnsHealth: $this->dnsHealthAllConfigured(latestMxScore: 79),
        );

        self::assertSame(DomainHealthFilter::Attention, $severity);
    }

    #[Test]
    public function attentionWhenVerifiedAndPassRateOkButMxScoreIsNull(): void
    {
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 95.0),
            dnsHealth: $this->dnsHealthAllConfigured(latestMxScore: null),
        );

        self::assertSame(DomainHealthFilter::Attention, $severity);
    }

    #[Test]
    public function attentionWhenVerifiedAndDnsHealthIsNull(): void
    {
        // Conservative branch: when we don't have DNS data we can't claim
        // Healthy. A freshly-added domain whose first DNS cron hasn't yet
        // run lives here until the snapshot lands.
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 100.0),
            dnsHealth: null,
        );

        self::assertSame(DomainHealthFilter::Attention, $severity);
    }

    #[Test]
    public function aFullyConfiguredDomainAwaitingItsFirstReportIsNotAccusedOfBeingUnhealthy(): void
    {
        // No pass-rate data arrives as null, not 0.0. Treating the absence of
        // data as a 0% pass rate flagged every brand-new, correctly-configured
        // domain as needing attention for its first day of life.
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: null),
            dnsHealth: $this->dnsHealthAllConfigured(),
        );

        self::assertSame(DomainHealthFilter::Healthy, $severity);
    }

    #[Test]
    public function aDomainAwaitingItsFirstReportIsStillJudgedOnItsDnsSetup(): void
    {
        // "No data yet" excuses the missing pass rate, not a missing SPF record.
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: null),
            dnsHealth: $this->dnsHealthAllConfigured(spfVerifiedAt: null),
        );

        self::assertSame(DomainHealthFilter::Attention, $severity);
    }

    #[Test]
    public function anUnverifiedDomainWithNoReportsIsStillUnverified(): void
    {
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: null, passRate: null),
            dnsHealth: $this->dnsHealthAllConfigured(),
        );

        self::assertSame(DomainHealthFilter::Unverified, $severity);
    }

    #[Test]
    public function aDomainWhereEveryMessageFailedIsStillFlaggedForAttention(): void
    {
        // The counterpart guard: a real, measured 0% must keep its warning. If
        // the "no data" branch ever swallowed a genuine zero we would be hiding
        // total authentication failure behind a green tick.
        $classifier = new DomainHealthClassifier();

        $severity = $classifier->classify(
            overview: $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 0.0),
            dnsHealth: $this->dnsHealthAllConfigured(),
        );

        self::assertSame(DomainHealthFilter::Attention, $severity);
    }

    #[Test]
    public function classifyOverviewMatchesClassifyForADomainAwaitingItsFirstReport(): void
    {
        $classifier = new DomainHealthClassifier();
        $overview = $this->overview(
            dmarcVerifiedAt: '2026-05-01 00:00:00',
            passRate: null,
            spfVerifiedAt: '2026-05-01 00:00:00',
            dkimVerifiedAt: '2026-05-01 00:00:00',
            latestSpfScore: 100,
            latestDkimScore: 100,
            latestDmarcScore: 100,
            latestMxScore: 95,
        );

        self::assertSame(
            DomainHealthFilter::Healthy,
            $classifier->classifyOverview($overview),
        );
        self::assertSame(
            $classifier->classify($overview, $this->buildDnsHealthFromOverview($overview)),
            $classifier->classifyOverview($overview),
        );
    }

    #[Test]
    public function classifyOverviewMatchesClassifyForUnverifiedDomains(): void
    {
        $classifier = new DomainHealthClassifier();
        $overview = $this->overview(
            dmarcVerifiedAt: null,
            passRate: 50.0,
        );

        self::assertSame(
            $classifier->classify($overview, $this->buildDnsHealthFromOverview($overview)),
            $classifier->classifyOverview($overview),
        );
    }

    #[Test]
    public function classifyOverviewMatchesClassifyForFullyHealthyDomains(): void
    {
        $classifier = new DomainHealthClassifier();
        $overview = $this->overview(
            dmarcVerifiedAt: '2026-05-01 00:00:00',
            passRate: 99.5,
            spfVerifiedAt: '2026-05-01 00:00:00',
            dkimVerifiedAt: '2026-05-01 00:00:00',
            latestSpfScore: 100,
            latestDkimScore: 100,
            latestDmarcScore: 100,
            latestMxScore: 95,
        );

        self::assertSame(
            DomainHealthFilter::Healthy,
            $classifier->classify($overview, $this->buildDnsHealthFromOverview($overview)),
        );
        self::assertSame(
            $classifier->classify($overview, $this->buildDnsHealthFromOverview($overview)),
            $classifier->classifyOverview($overview),
        );
    }

    #[Test]
    public function classifyOverviewMatchesClassifyForVerifiedButMissingSpf(): void
    {
        $classifier = new DomainHealthClassifier();
        $overview = $this->overview(
            dmarcVerifiedAt: '2026-05-01 00:00:00',
            passRate: 99.5,
            spfVerifiedAt: null,
            dkimVerifiedAt: '2026-05-01 00:00:00',
            latestSpfScore: null,
            latestDkimScore: 100,
            latestDmarcScore: 100,
            latestMxScore: 95,
        );

        self::assertSame(
            DomainHealthFilter::Attention,
            $classifier->classifyOverview($overview),
        );
        self::assertSame(
            $classifier->classify($overview, $this->buildDnsHealthFromOverview($overview)),
            $classifier->classifyOverview($overview),
        );
    }

    #[Test]
    public function classifyOverviewMatchesClassifyForVerifiedAllProtocolsButLowPassRate(): void
    {
        $classifier = new DomainHealthClassifier();
        $overview = $this->overview(
            dmarcVerifiedAt: '2026-05-01 00:00:00',
            passRate: 65.0,
            spfVerifiedAt: '2026-05-01 00:00:00',
            dkimVerifiedAt: '2026-05-01 00:00:00',
            latestSpfScore: 100,
            latestDkimScore: 100,
            latestDmarcScore: 100,
            latestMxScore: 95,
        );

        self::assertSame(
            DomainHealthFilter::Attention,
            $classifier->classifyOverview($overview),
        );
        self::assertSame(
            $classifier->classify($overview, $this->buildDnsHealthFromOverview($overview)),
            $classifier->classifyOverview($overview),
        );
    }

    #[Test]
    public function classifyOverviewMatchesClassifyForVerifiedDomainMissingDnsSnapshot(): void
    {
        // The single-input variant has no way to distinguish "we haven't
        // checked yet" from "we checked and it's broken" — both manifest as
        // null snapshot fields on the overview row. classify() with a null
        // DnsHealthOverviewResult is the explicit equivalent. The unified
        // rule treats both as Attention.
        $classifier = new DomainHealthClassifier();
        $overview = $this->overview(
            dmarcVerifiedAt: '2026-05-01 00:00:00',
            passRate: 100.0,
        );

        self::assertSame(
            DomainHealthFilter::Attention,
            $classifier->classifyOverview($overview),
        );
        self::assertSame(
            $classifier->classify($overview, null),
            $classifier->classifyOverview($overview),
        );
    }

    #[Test]
    public function aDomainWhoseLatestSpfCheckFailsIsNotHealthyEvenThoughItWasVerifiedOnce(): void
    {
        // `spf_verified_at` is a HISTORICAL marker: CheckDomainDnsHandler sets it
        // when a check passes and never clears it when a later check fails. So a
        // domain whose SPF record was deleted last month still carries the
        // timestamp from when it worked. Judging health by that timestamp gave
        // this domain the green "fully healthy" chip and dropped it out of "Needs
        // your attention" — the alert email in the user's inbox was the only place
        // the regression was visible anywhere.
        $classifier = new DomainHealthClassifier();

        $overview = $this->overview(
            dmarcVerifiedAt: '2026-05-01 00:00:00',
            passRate: 99.0,
            spfVerifiedAt: '2026-05-01 00:00:00',
            dkimVerifiedAt: '2026-05-01 00:00:00',
            latestMxScore: 95,
            spfCheckValid: false,
            dkimCheckValid: true,
            dmarcCheckValid: true,
            mxCheckValid: true,
        );

        self::assertSame(
            DomainHealthFilter::Attention,
            $classifier->classifyOverview($overview),
            'A currently-failing SPF check must beat a stale spf_verified_at.',
        );
        self::assertSame(
            DomainHealthFilter::Attention,
            $classifier->classify($overview, $this->buildDnsHealthFromOverview($overview)),
            'The banner and the list card must reach the same verdict from the same check row.',
        );
    }

    #[Test]
    public function aPassingCheckCountsAsConfiguredWithoutAnyVerifiedAtOrSnapshotScore(): void
    {
        // The other direction: the stored check row is authoritative when it says
        // YES too. A domain checked before the nightly snapshot sweep has run has
        // no MX score and, for a check path that does not stamp them, no
        // verified-at columns — but we HAVE looked and everything resolved. Calling
        // that Attention would be the "not checked yet reads as broken" bug from
        // the other side.
        $classifier = new DomainHealthClassifier();

        $overview = $this->overview(
            dmarcVerifiedAt: '2026-05-01 00:00:00',
            passRate: 99.0,
            spfCheckValid: true,
            dkimCheckValid: true,
            dmarcCheckValid: true,
            mxCheckValid: true,
        );

        self::assertSame(DomainHealthFilter::Healthy, $classifier->classifyOverview($overview));
        self::assertSame(DomainHealthFilter::Healthy, $classifier->classify($overview, $this->buildDnsHealthFromOverview($overview)));
    }

    #[Test]
    public function aProtocolWithNoStoredCheckStillFallsBackToTheVerifiedAtColumns(): void
    {
        // "No check row for this protocol" is neither a pass nor a fail, so the
        // legacy derivation still decides. Without this, every domain seeded before
        // per-protocol check rows existed would flip to Attention overnight.
        $classifier = new DomainHealthClassifier();

        $overview = $this->overview(
            dmarcVerifiedAt: '2026-05-01 00:00:00',
            passRate: 99.0,
            spfVerifiedAt: '2026-05-01 00:00:00',
            dkimVerifiedAt: '2026-05-01 00:00:00',
            latestMxScore: 95,
        );

        self::assertSame(DomainHealthFilter::Healthy, $classifier->classifyOverview($overview));
    }

    #[Test]
    public function aBrokenRecordBeatsTheVerificationTimestampThatUsedToProveItWorked(): void
    {
        // A domain whose DKIM key has since been removed still carries the
        // `dkim_verified_at` stamp from the day it worked. The stored check
        // verdict has to win, or "it was fine last month" reads as "it is fine".
        $classifier = new DomainHealthClassifier();
        $verifiedWithGoodPassRate = $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 99.0);

        self::assertSame(
            DomainHealthFilter::Attention,
            $classifier->classify($verifiedWithGoodPassRate, $this->dnsHealthAllConfigured(dkimCheckValid: false)),
        );
        self::assertSame(
            DomainHealthFilter::Healthy,
            $classifier->classify($verifiedWithGoodPassRate, $this->dnsHealthAllConfigured(dkimCheckValid: true)),
        );
    }

    #[Test]
    public function aFailingMxCheckBeatsAHealthySnapshotScore(): void
    {
        // MX has no verified-at column, so before this its only input was the
        // nightly snapshot score — which keeps reporting the last good number
        // until the next sweep overwrites it.
        $classifier = new DomainHealthClassifier();

        self::assertSame(
            DomainHealthFilter::Attention,
            $classifier->classify(
                $this->overview(dmarcVerifiedAt: '2026-05-01 00:00:00', passRate: 99.0),
                $this->dnsHealthAllConfigured(latestMxScore: 95, mxCheckValid: false),
            ),
        );
    }

    #[Test]
    public function theClassifierOffersNoShortcutThatAnswersOnlyPartOfTheRule(): void
    {
        // This class exists because three different rules were painting the same
        // domain three different colours. A public helper that answers ONE of its
        // questions — "are all four protocols configured?", with no DMARC-verified
        // precedence and no pass-rate arm — is a fourth rule waiting to be picked
        // up by name, and it already was: `/app/domains` counted a 30%-pass-rate
        // domain as "Fully healthy" while its own badge and the list behind the
        // link both said Attention.
        //
        // A docblock warning does not stop that; the next person holding a
        // DnsHealthOverviewResult finds `isFullyHealthy()` in autocomplete and
        // believes the name. Every public entry point has to return the verdict.
        $publicMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(DomainHealthClassifier::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($publicMethods);

        self::assertSame(
            ['classify', 'classifyOverview'],
            $publicMethods,
            'Every public method on the single source of truth must answer the whole question and return a DomainHealthFilter.',
        );
    }

    private function overview(
        ?string $dmarcVerifiedAt,
        ?float $passRate,
        ?string $spfVerifiedAt = null,
        ?string $dkimVerifiedAt = null,
        ?int $latestSpfScore = null,
        ?int $latestDkimScore = null,
        ?int $latestDmarcScore = null,
        ?int $latestMxScore = null,
        ?bool $spfCheckValid = null,
        ?bool $dkimCheckValid = null,
        ?bool $dmarcCheckValid = null,
        ?bool $mxCheckValid = null,
    ): DomainOverviewResult {
        return new DomainOverviewResult(
            domainId: 'domain-id',
            domainName: 'example.com',
            totalReports: 0,
            latestReportDate: null,
            passRate: $passRate,
            teamId: 'team-id',
            teamName: 'Team',
            dmarcVerifiedAt: $dmarcVerifiedAt,
            spfVerifiedAt: $spfVerifiedAt,
            dkimVerifiedAt: $dkimVerifiedAt,
            latestSpfScore: $latestSpfScore,
            latestDkimScore: $latestDkimScore,
            latestDmarcScore: $latestDmarcScore,
            latestMxScore: $latestMxScore,
            spfCheckValid: $spfCheckValid,
            dkimCheckValid: $dkimCheckValid,
            dmarcCheckValid: $dmarcCheckValid,
            mxCheckValid: $mxCheckValid,
        );
    }

    /**
     * Helper for building an all-configured `DnsHealthOverviewResult` with
     * surgical overrides. Each override (spfVerifiedAt / dkimVerifiedAt /
     * dmarcVerifiedAt) is a tri-state: `false` (the sentinel default) keeps
     * the configured value; `null` explicitly nulls it out; a `DateTimeImmutable`
     * sets a specific value. Named arguments + `false` sentinel avoid the
     * `?? new DateTime()` trap where `null` overrides would silently snap
     * back to the default.
     */
    private function dnsHealthAllConfigured(
        \DateTimeImmutable|false|null $spfVerifiedAt = false,
        \DateTimeImmutable|false|null $dkimVerifiedAt = false,
        \DateTimeImmutable|false|null $dmarcVerifiedAt = false,
        ?int $latestMxScore = 95,
        ?bool $spfCheckValid = null,
        ?bool $dkimCheckValid = null,
        ?bool $dmarcCheckValid = null,
        ?bool $mxCheckValid = null,
    ): DnsHealthOverviewResult {
        $configured = new \DateTimeImmutable('2026-05-01');

        return new DnsHealthOverviewResult(
            domainId: 'domain-id',
            domainName: 'example.com',
            spfVerifiedAt: false === $spfVerifiedAt ? $configured : $spfVerifiedAt,
            dkimVerifiedAt: false === $dkimVerifiedAt ? $configured : $dkimVerifiedAt,
            dmarcVerifiedAt: false === $dmarcVerifiedAt ? $configured : $dmarcVerifiedAt,
            latestSnapshotGrade: 'A',
            latestSnapshotScore: 95,
            latestSpfScore: 100,
            latestDkimScore: 100,
            latestDmarcScore: 100,
            latestMxScore: $latestMxScore,
            latestCheckedAt: $configured,
            spfCheckValid: $spfCheckValid,
            dkimCheckValid: $dkimCheckValid,
            dmarcCheckValid: $dmarcCheckValid,
            mxCheckValid: $mxCheckValid,
        );
    }

    /**
     * Synthetic `DnsHealthOverviewResult` built from the joined-in fields on
     * a `DomainOverviewResult` row — used to assert parity between
     * `classify()` and `classifyOverview()` for the same domain. The
     * overrides defaulting to `null` make the resulting DTO act as if the
     * DNS cron hasn't recorded a snapshot yet.
     */
    private function buildDnsHealthFromOverview(DomainOverviewResult $overview): DnsHealthOverviewResult
    {
        return new DnsHealthOverviewResult(
            domainId: $overview->domainId,
            domainName: $overview->domainName,
            spfVerifiedAt: null !== $overview->spfVerifiedAt ? new \DateTimeImmutable($overview->spfVerifiedAt) : null,
            dkimVerifiedAt: null !== $overview->dkimVerifiedAt ? new \DateTimeImmutable($overview->dkimVerifiedAt) : null,
            dmarcVerifiedAt: null !== $overview->dmarcVerifiedAt ? new \DateTimeImmutable($overview->dmarcVerifiedAt) : null,
            latestSnapshotGrade: null,
            latestSnapshotScore: null,
            latestSpfScore: $overview->latestSpfScore,
            latestDkimScore: $overview->latestDkimScore,
            latestDmarcScore: $overview->latestDmarcScore,
            latestMxScore: $overview->latestMxScore,
            latestCheckedAt: null,
            spfCheckValid: $overview->spfCheckValid,
            dkimCheckValid: $overview->dkimCheckValid,
            dmarcCheckValid: $overview->dmarcCheckValid,
            mxCheckValid: $overview->mxCheckValid,
        );
    }
}
