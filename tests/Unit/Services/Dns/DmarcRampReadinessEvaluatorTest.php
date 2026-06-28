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
        $domain->cnameVerifiedAt = $cnameVerified ? $this->now->modify('-5 days') : null;
        $domain->lastPolicyChangeAt = $this->now->modify(sprintf('-%d days', $lastChangeDaysAgo));
        $domain->autoRampPausedAt = $paused ? $this->now->modify('-1 day') : null;

        return $domain;
    }
}
