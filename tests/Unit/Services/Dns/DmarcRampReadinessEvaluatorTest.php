<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Results\DomainReadinessResult;
use App\Services\Dns\DmarcRampReadinessEvaluator;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

final class DmarcRampReadinessEvaluatorTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-06-28 12:00:00');
    }

    #[Test]
    public function noneToQuarantineNeeds95PercentOver30DaysAndTwoSources(): void
    {
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 10),
            new DomainReadinessResult(passRate: 96.0, reportsCount: 5, messageVolume: 5000, distinctSources: 3, authorizedFailureVolume: 0),
        );

        self::assertSame(AutoRampStage::Monitoring, $result->currentStage);
        self::assertTrue($result->ready);
        self::assertTrue($result->eligibleForNextTier);
        self::assertNotNull($result->recommendedNextPolicy);
        self::assertSame(DmarcPolicy::Quarantine, $result->recommendedNextPolicy->p);

        $tooFewSources = $this->evaluate(
            $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 10),
            new DomainReadinessResult(96.0, 5, 5000, 1, 0),
        );
        self::assertFalse($tooFewSources->eligibleForNextTier);
        self::assertContains('too_few_sources', $tooFewSources->blockingReasons);
    }

    #[Test]
    public function blocksAdvanceOnThinDataEvenAtHighPassRate(): void
    {
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::None, firstReportDaysAgo: 10, cnameVerified: true, lastChangeDaysAgo: 10),
            new DomainReadinessResult(100.0, 12, 9000, 6, 0),
        );

        self::assertFalse($result->ready);
        self::assertFalse($result->eligibleForNextTier);
        self::assertContains('thin_data', $result->blockingReasons);
    }

    #[Test]
    public function blocksAdvanceWhenTooFewReports(): void
    {
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 10),
            new DomainReadinessResult(99.0, 2, 200, 3, 0),
        );

        self::assertFalse($result->ready);
        self::assertContains('too_few_reports', $result->blockingReasons);
    }

    #[Test]
    public function treatsADomainWithNoFirstReportAsZeroDaysOfData(): void
    {
        $domain = $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 10);
        $domain->firstReportAt = null;

        $result = $this->evaluate($domain, new DomainReadinessResult(100.0, 12, 9000, 6, 0));

        self::assertSame(0, $result->daysOfData);
        self::assertContains('thin_data', $result->blockingReasons);
    }

    #[Test]
    public function requiresVerifiedCnameBeforeRecommendingTightening(): void
    {
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: false, lastChangeDaysAgo: 10),
            new DomainReadinessResult(99.0, 9, 9000, 5, 0),
        );

        self::assertTrue($result->ready, 'data qualifies');
        self::assertFalse($result->eligibleForNextTier, 'but no verified CNAME');
        self::assertContains('cname_not_verified', $result->blockingReasons);
    }

    #[Test]
    public function treatsAVerificationOlderThanOneDnsSweepCycleAsNotVerified(): void
    {
        // `cnameVerifiedAt` is refreshed by the 03:00 sweep and cleared the moment
        // a sweep cannot see the CNAME. A timestamp older than one sweep cycle
        // therefore does not mean "the CNAME is live" — it means no sweep has
        // confirmed it since, which is indistinguishable from the CNAME having been
        // deleted. Acting on it would publish full enforcement for a policy record
        // that may no longer be served at all.
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 10, cnameVerifiedHoursAgo: 48),
            new DomainReadinessResult(99.0, 9, 9000, 5, 0),
        );

        self::assertTrue($result->ready, 'the mail data still qualifies');
        self::assertFalse($result->cnameVerified, 'a stale confirmation is not a confirmation');
        self::assertFalse($result->eligibleForNextTier);
        self::assertContains('cname_not_verified', $result->blockingReasons, 'Stale verification fails closed through the same rail as a missing CNAME.');
    }

    #[Test]
    public function acceptsAVerificationFromTheMostRecentNightlySweep(): void
    {
        // The other side of the boundary: a normal healthy host hands the ramp a
        // timestamp a few hours old, and that must keep working — a gate that
        // blocked every domain would be indistinguishable from switching
        // auto-drive off.
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 10, cnameVerifiedHoursAgo: 25),
            new DomainReadinessResult(99.0, 9, 9000, 5, 0),
        );

        self::assertTrue($result->cnameVerified);
        self::assertTrue($result->eligibleForNextTier);
        self::assertNotContains('cname_not_verified', $result->blockingReasons);
    }

    #[Test]
    public function enforcesSevenDayDwell(): void
    {
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 2),
            new DomainReadinessResult(99.0, 9, 9000, 5, 0),
        );

        self::assertTrue($result->ready);
        self::assertFalse($result->eligibleForNextTier);
        self::assertContains('dwell_not_satisfied', $result->blockingReasons);
    }

    #[Test]
    public function aDomainWithNoRecordedPolicyChangeHasNotSatisfiedTheDwell(): void
    {
        // No recorded change is the ABSENCE of history, not proof that a week has
        // passed since the last tightening. Reading it as "dwell satisfied" skipped
        // the mandatory 7-day observation window outright for legacy and
        // backfilled rows — the rows whose history we know least about.
        $domain = $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 10);
        $domain->lastPolicyChangeAt = null;

        $result = $this->evaluate($domain, new DomainReadinessResult(99.0, 9, 9000, 5, 0));

        self::assertTrue($result->ready, 'the mail data still qualifies');
        self::assertFalse($result->eligibleForNextTier);
        self::assertContains('dwell_not_satisfied', $result->blockingReasons);
    }

    #[Test]
    public function pausedRampIsNeverEligible(): void
    {
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 10, paused: true),
            new DomainReadinessResult(99.0, 9, 9000, 5, 0),
        );

        self::assertFalse($result->eligibleForNextTier);
        self::assertContains('auto_ramp_paused', $result->blockingReasons);
    }

    #[Test]
    public function quarantineToRejectNeeds99PercentOver60Days(): void
    {
        $eligible = $this->evaluate(
            $this->domain(DmarcPolicy::Quarantine, firstReportDaysAgo: 70, cnameVerified: true, lastChangeDaysAgo: 14),
            new DomainReadinessResult(99.5, 20, 50000, 8, 0),
        );
        self::assertTrue($eligible->eligibleForNextTier);
        self::assertNotNull($eligible->recommendedNextPolicy);
        self::assertSame(DmarcPolicy::Reject, $eligible->recommendedNextPolicy->p);

        $belowThreshold = $this->evaluate(
            $this->domain(DmarcPolicy::Quarantine, firstReportDaysAgo: 70, cnameVerified: true, lastChangeDaysAgo: 14),
            new DomainReadinessResult(98.0, 20, 50000, 8, 0),
        );
        self::assertFalse($belowThreshold->eligibleForNextTier);
        self::assertContains('pass_rate_below_threshold', $belowThreshold->blockingReasons);

        $tooShort = $this->evaluate(
            $this->domain(DmarcPolicy::Quarantine, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 14),
            new DomainReadinessResult(99.9, 20, 50000, 8, 0),
        );
        self::assertContains('thin_data', $tooShort->blockingReasons);
    }

    #[Test]
    public function flagsRegressionWhenAnAuthorizedSourceStartsFailing(): void
    {
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::Quarantine, firstReportDaysAgo: 70, cnameVerified: true, lastChangeDaysAgo: 14),
            new DomainReadinessResult(99.9, 20, 50000, 8, authorizedFailureVolume: 120),
        );

        self::assertTrue($result->regressionDetected);
        self::assertFalse($result->eligibleForNextTier);
        self::assertContains('authorized_senders_failing', $result->blockingReasons);
    }

    #[Test]
    public function aDomainWithNoMeasuredMailNeverAdvancesTowardsFullEnforcement(): void
    {
        // The dangerous shape. At p=quarantine every other rung of the ladder is
        // satisfied by history alone — 70 days since the first report, no minimum
        // report count, no minimum source count, a fresh CNAME, dwell served — so
        // the pass-rate gate is the ONLY thing standing between this domain and
        // p=reject. Its 60-day window is empty (reports aged out of retention),
        // so there is no rate at all.
        //
        // Advancing here would publish full enforcement on zero evidence and
        // blackhole whatever real mail the domain still sends. The gate must
        // treat "unmeasured" as "not proven safe" — a guard written as
        // `null !== $passRate && $passRate < $threshold` skips the block on null
        // and does the opposite.
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::Quarantine, firstReportDaysAgo: 70, cnameVerified: true, lastChangeDaysAgo: 14),
            new DomainReadinessResult(passRate: null, reportsCount: 0, messageVolume: 0, distinctSources: 0, authorizedFailureVolume: 0),
        );

        self::assertFalse($result->ready, 'No evidence is not qualifying evidence.');
        self::assertFalse($result->eligibleForNextTier, 'A domain with nothing measured must never be advanced to p=reject.');
        self::assertContains('no_pass_rate_data', $result->blockingReasons);
        self::assertNotContains(
            'pass_rate_below_threshold',
            $result->blockingReasons,
            'There is no rate to be below the threshold — saying otherwise invents a measurement in the reason the user reads.',
        );
        self::assertNull($result->passRate, 'The verdict carries the absence through, so the card can say "we have not measured yet".');
    }

    #[Test]
    public function aDomainWithNoMeasuredMailIsAlsoHeldAtTheFirstRung(): void
    {
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::None, firstReportDaysAgo: 40, cnameVerified: true, lastChangeDaysAgo: 10),
            new DomainReadinessResult(passRate: null, reportsCount: 0, messageVolume: 0, distinctSources: 0, authorizedFailureVolume: 0),
        );

        self::assertFalse($result->ready);
        self::assertFalse($result->eligibleForNextTier);
        self::assertContains('no_pass_rate_data', $result->blockingReasons);
    }

    #[Test]
    public function rejectIsTerminal(): void
    {
        $result = $this->evaluate(
            $this->domain(DmarcPolicy::Reject, firstReportDaysAgo: 90, cnameVerified: true, lastChangeDaysAgo: 30),
            new DomainReadinessResult(100.0, 30, 80000, 10, 0),
        );

        self::assertSame(AutoRampStage::Reject, $result->currentStage);
        self::assertNull($result->recommendedNextPolicy);
        self::assertFalse($result->eligibleForNextTier);
        self::assertContains('already_at_full_enforcement', $result->blockingReasons);
    }

    #[Test]
    public function derivesCurrentStageFromThePublishedPolicy(): void
    {
        $domain = $this->domain(DmarcPolicy::Quarantine, firstReportDaysAgo: 70, cnameVerified: true, lastChangeDaysAgo: 14);
        // A stale stored stage must not override the published policy.
        $domain->autoRampStage = AutoRampStage::Monitoring;

        $result = $this->evaluate($domain, new DomainReadinessResult(99.9, 20, 50000, 8, 0));

        self::assertSame(AutoRampStage::Quarantine, $result->currentStage);
    }

    private function evaluate(MonitoredDomain $domain, DomainReadinessResult $signals): \App\Results\RampReadinessResult
    {
        return (new DmarcRampReadinessEvaluator(new MockClock($this->now)))->evaluate($domain, $signals);
    }

    private function domain(
        DmarcPolicy $p,
        int $firstReportDaysAgo,
        bool $cnameVerified,
        int $lastChangeDaysAgo,
        bool $paused = false,
        int $cnameVerifiedHoursAgo = 2,
    ): MonitoredDomain {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Readiness',
            slug: 'readiness-'.Uuid::uuid7()->toString(),
            createdAt: $this->now,
        );

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'acme.example',
            createdAt: $this->now->modify('-120 days'),
            firstReportAt: $this->now->modify(sprintf('-%d days', $firstReportDaysAgo)),
        );
        $domain->managedPolicyP = $p;
        // Two hours old — what the 03:00 DNS sweep leaves for the 05:30 ramp.
        // Anything older than one sweep cycle is not accepted as live
        // verification, so the default has to be a realistic fresh value.
        $domain->cnameVerifiedAt = $cnameVerified ? $this->now->modify(sprintf('-%d hours', $cnameVerifiedHoursAgo)) : null;
        $domain->lastPolicyChangeAt = $this->now->modify(sprintf('-%d days', $lastChangeDaysAgo));
        $domain->autoRampPausedAt = $paused ? $this->now->modify('-1 day') : null;

        return $domain;
    }
}
