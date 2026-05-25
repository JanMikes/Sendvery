<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\PassRateAggregate;
use App\Results\TopFailingSenderResult;
use App\Services\PassRateRegressionAdvisor;
use App\Value\PassRateRegressionSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure-computation coverage for {@see PassRateRegressionAdvisor} branches
 * (TASK-093). The advisor is the only place that decides whether to surface
 * a "pass rate dropped this week" banner on `/app/reports` — locking the
 * eligibility rules here keeps the team's headline-health surface
 * deterministic across every render.
 */
final class PassRateRegressionAdvisorTest extends TestCase
{
    #[Test]
    public function regressionWhenDeltaExceedsThresholdAndBaselineAboveSeventy(): void
    {
        $advisor = new PassRateRegressionAdvisor();

        // Baseline 91%, current 73%, delta -18pp, 80 reports — clears every floor
        // including the TASK-109 MIN_SAMPLE_SIZE = 50 minimum on both windows.
        $window7d = new PassRateAggregate(passRate: 73.0, reportCount: 80, totalMessages: 1000, failingMessages: 270);
        $baseline30d = new PassRateAggregate(passRate: 91.0, reportCount: 100, totalMessages: 5000, failingMessages: 450);

        $result = $advisor->advise($window7d, $baseline30d, $this->makeTopFailing(failingCount: 250));

        self::assertSame(PassRateRegressionSeverity::Regression, $result->severity);
        self::assertSame(73.0, $result->currentRate7d);
        self::assertSame(91.0, $result->baselineRate30d);
        self::assertSame(-18.0, $result->delta);
        self::assertNotNull($result->topFailingSender);
        self::assertSame(270, $result->totalFailingMessages7d);
        // 250 of 270 failures = 92.59% → rounded to 93
        self::assertSame(93.0, $result->percentFromTopSender());
    }

    #[Test]
    public function regressionFiresExactlyAtTenPpDrop(): void
    {
        // Boundary check: -10pp exactly should fire (the `>` in the rule is
        // INCLUSIVE on -10). Pin the boundary so future refactors can't
        // silently flip the comparator. Both windows at ≥ 50 reports to clear
        // the TASK-109 MIN_SAMPLE_SIZE floor.
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 80.0, reportCount: 60, totalMessages: 500, failingMessages: 100);
        $baseline30d = new PassRateAggregate(passRate: 90.0, reportCount: 80, totalMessages: 2000, failingMessages: 200);

        $result = $advisor->advise($window7d, $baseline30d, null);

        self::assertSame(PassRateRegressionSeverity::Regression, $result->severity);
        self::assertSame(-10.0, $result->delta);
    }

    #[Test]
    public function stableJustBelowRegressionThresholdOnDelta(): void
    {
        // 9.9pp drop — one tenth below the regression floor. Volumes well
        // above the TASK-109 floor so the suppression cannot be conflated
        // with the delta-threshold check this test is locking.
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 80.1, reportCount: 60, totalMessages: 500, failingMessages: 100);
        $baseline30d = new PassRateAggregate(passRate: 90.0, reportCount: 80, totalMessages: 2000, failingMessages: 200);

        $result = $advisor->advise($window7d, $baseline30d, null);

        self::assertSame(PassRateRegressionSeverity::Stable, $result->severity);
    }

    #[Test]
    public function stableWhenBaselineAlreadyBroken(): void
    {
        // 60% → 40% is a -20pp drop but the baseline is already in the
        // "needs help" zone (< 70%) — surfacing a regression banner adds
        // noise without adding value. Stable. Volumes intentionally above
        // the TASK-109 MIN_SAMPLE_SIZE floor so the baseline-broken rule is
        // the one being exercised here, not the small-sample short-circuit.
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 40.0, reportCount: 60, totalMessages: 500, failingMessages: 300);
        $baseline30d = new PassRateAggregate(passRate: 60.0, reportCount: 100, totalMessages: 2000, failingMessages: 800);

        $result = $advisor->advise($window7d, $baseline30d, null);

        self::assertSame(PassRateRegressionSeverity::Stable, $result->severity);
    }

    #[Test]
    public function stableWhenFewerThan20Reports7d(): void
    {
        // 19 reports — below the small-sample floor. A massive-looking drop
        // here is more likely noise than signal.
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 60.0, reportCount: 19, totalMessages: 200, failingMessages: 80);
        $baseline30d = new PassRateAggregate(passRate: 95.0, reportCount: 100, totalMessages: 4000, failingMessages: 200);

        $result = $advisor->advise($window7d, $baseline30d, null);

        self::assertSame(PassRateRegressionSeverity::Stable, $result->severity);
    }

    #[Test]
    public function stableWhenFewerThan20Reports30dBaseline(): void
    {
        // Even if the 7d window has enough samples, a thin baseline can't
        // legitimately anchor a regression comparison.
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 60.0, reportCount: 30, totalMessages: 500, failingMessages: 200);
        $baseline30d = new PassRateAggregate(passRate: 95.0, reportCount: 5, totalMessages: 50, failingMessages: 2);

        $result = $advisor->advise($window7d, $baseline30d, null);

        self::assertSame(PassRateRegressionSeverity::Stable, $result->severity);
    }

    #[Test]
    public function stableWhenCurrentWindowBelowMinSampleSize(): void
    {
        // TASK-109: current 7-day window has 30 reports — clears the legacy
        // 20-report noise floor but falls below the 50-report MIN_SAMPLE_SIZE
        // floor. A 15pp drop on 30 reports is within random variance, so the
        // banner must suppress even though the delta would otherwise fire.
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 80.0, reportCount: 30, totalMessages: 500, failingMessages: 100);
        $baseline30d = new PassRateAggregate(passRate: 95.0, reportCount: 80, totalMessages: 4000, failingMessages: 200);

        $result = $advisor->advise($window7d, $baseline30d, $this->makeTopFailing(failingCount: 80));

        self::assertSame(PassRateRegressionSeverity::Stable, $result->severity);
    }

    #[Test]
    public function stableWhenBaselineWindowBelowMinSampleSize(): void
    {
        // TASK-109: prior 30-day baseline has 30 reports — clears the legacy
        // 20-report floor but falls below the 50-report MIN_SAMPLE_SIZE floor.
        // A thin baseline can't anchor a regression call no matter how large
        // the current window is.
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 80.0, reportCount: 80, totalMessages: 1000, failingMessages: 200);
        $baseline30d = new PassRateAggregate(passRate: 95.0, reportCount: 30, totalMessages: 1500, failingMessages: 75);

        $result = $advisor->advise($window7d, $baseline30d, $this->makeTopFailing(failingCount: 150));

        self::assertSame(PassRateRegressionSeverity::Stable, $result->severity);
    }

    #[Test]
    public function regressionFiresWhenBothWindowsAtMinSampleSize(): void
    {
        // TASK-109 boundary: both windows at exactly 80 reports (clearly
        // above the 50 floor), 15pp drop, baseline above 70% — the banner
        // must still fire. Locks "existing behaviour preserved when both
        // windows clear the new floor".
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 80.0, reportCount: 80, totalMessages: 1000, failingMessages: 200);
        $baseline30d = new PassRateAggregate(passRate: 95.0, reportCount: 80, totalMessages: 4000, failingMessages: 200);

        $result = $advisor->advise($window7d, $baseline30d, $this->makeTopFailing(failingCount: 150));

        self::assertSame(PassRateRegressionSeverity::Regression, $result->severity);
        self::assertSame(-15.0, $result->delta);
        self::assertNotNull($result->topFailingSender);
    }

    #[Test]
    public function stableWhenZeroBaselineData(): void
    {
        // Edge case: a brand-new team with no historical reports. Both
        // windows are empty — must not produce a banner.
        $advisor = new PassRateRegressionAdvisor();

        $result = $advisor->advise(
            PassRateAggregate::empty(),
            PassRateAggregate::empty(),
            null,
        );

        self::assertSame(PassRateRegressionSeverity::Stable, $result->severity);
    }

    #[Test]
    public function improvementWhenDeltaExceedsThresholdAndBaselineBelowNinety(): void
    {
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 92.0, reportCount: 50, totalMessages: 1000, failingMessages: 80);
        $baseline30d = new PassRateAggregate(passRate: 75.0, reportCount: 100, totalMessages: 4000, failingMessages: 1000);

        $result = $advisor->advise($window7d, $baseline30d, null);

        self::assertSame(PassRateRegressionSeverity::Improvement, $result->severity);
        self::assertSame(92.0, $result->currentRate7d);
        self::assertSame(17.0, $result->delta);
        // Improvement state intentionally drops the failing-sender link.
        self::assertNull($result->topFailingSender);
    }

    #[Test]
    public function improvementFiresExactlyAtTenPpRise(): void
    {
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 88.0, reportCount: 50, totalMessages: 1000, failingMessages: 120);
        $baseline30d = new PassRateAggregate(passRate: 78.0, reportCount: 100, totalMessages: 4000, failingMessages: 880);

        $result = $advisor->advise($window7d, $baseline30d, null);

        self::assertSame(PassRateRegressionSeverity::Improvement, $result->severity);
    }

    #[Test]
    public function improvementSuppressedWhenBaselineAlreadyHealthy(): void
    {
        // Baseline 92% → window 99.5% is a +7.5pp climb (below threshold) but
        // even at +10pp the suppression on baseline >= 90% should keep this
        // quiet. Confirm both paths.
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 99.0, reportCount: 50, totalMessages: 1000, failingMessages: 10);
        $baseline30d = new PassRateAggregate(passRate: 89.0, reportCount: 100, totalMessages: 4000, failingMessages: 440);

        $result = $advisor->advise($window7d, $baseline30d, null);

        // 89 → 99 = +10pp, baseline below 90 → improvement should fire here.
        self::assertSame(PassRateRegressionSeverity::Improvement, $result->severity);

        // Now nudge baseline above 90 — must suppress.
        $window7d2 = new PassRateAggregate(passRate: 100.0, reportCount: 50, totalMessages: 1000, failingMessages: 0);
        $baseline30d2 = new PassRateAggregate(passRate: 90.0, reportCount: 100, totalMessages: 4000, failingMessages: 400);
        $result2 = $advisor->advise($window7d2, $baseline30d2, null);
        self::assertSame(PassRateRegressionSeverity::Stable, $result2->severity);
    }

    #[Test]
    public function stableWithNoChange(): void
    {
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 95.0, reportCount: 50, totalMessages: 1000, failingMessages: 50);
        $baseline30d = new PassRateAggregate(passRate: 95.0, reportCount: 100, totalMessages: 4000, failingMessages: 200);

        $result = $advisor->advise($window7d, $baseline30d, null);

        self::assertSame(PassRateRegressionSeverity::Stable, $result->severity);
    }

    #[Test]
    public function percentFromTopSenderHandlesZeroFailingMessages(): void
    {
        // Defensive: 0/0 must not divide-by-zero — the helper returns null.
        // Sample sizes well above the TASK-109 MIN_SAMPLE_SIZE floor so the
        // regression actually fires and the helper is reachable.
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 80.0, reportCount: 60, totalMessages: 500, failingMessages: 0);
        $baseline30d = new PassRateAggregate(passRate: 95.0, reportCount: 80, totalMessages: 2000, failingMessages: 100);

        $result = $advisor->advise($window7d, $baseline30d, $this->makeTopFailing(failingCount: 0));

        self::assertSame(PassRateRegressionSeverity::Regression, $result->severity);
        self::assertNull($result->percentFromTopSender());
    }

    #[Test]
    public function percentFromTopSenderHandlesNullSender(): void
    {
        // Regression can still fire without a named culprit (e.g. failures
        // spread evenly across many senders). The helper returns null and
        // the banner skips the "Investigate this sender" link. Sample sizes
        // above the TASK-109 MIN_SAMPLE_SIZE floor so the regression path
        // actually reaches the assertion.
        $advisor = new PassRateRegressionAdvisor();
        $window7d = new PassRateAggregate(passRate: 80.0, reportCount: 60, totalMessages: 500, failingMessages: 100);
        $baseline30d = new PassRateAggregate(passRate: 95.0, reportCount: 80, totalMessages: 2000, failingMessages: 100);

        $result = $advisor->advise($window7d, $baseline30d, null);

        self::assertSame(PassRateRegressionSeverity::Regression, $result->severity);
        self::assertNull($result->topFailingSender);
        self::assertNull($result->percentFromTopSender());
    }

    #[Test]
    public function aggregateEmptyFactoryReturnsAllZeros(): void
    {
        $empty = PassRateAggregate::empty();

        self::assertSame(0.0, $empty->passRate);
        self::assertSame(0, $empty->reportCount);
        self::assertSame(0, $empty->totalMessages);
        self::assertSame(0, $empty->failingMessages);
    }

    #[Test]
    public function aggregateFromDatabaseRowHandlesNullPassRate(): void
    {
        // PostgreSQL returns NULL for SUM/AVG over empty windows — the DTO
        // must clamp them to 0.0 instead of letting the null leak into the
        // float-typed property.
        $aggregate = PassRateAggregate::fromDatabaseRow([
            'pass_rate' => null,
            'report_count' => '0',
            'total_messages' => null,
            'failing_messages' => null,
        ]);

        self::assertSame(0.0, $aggregate->passRate);
        self::assertSame(0, $aggregate->reportCount);
        self::assertSame(0, $aggregate->totalMessages);
        self::assertSame(0, $aggregate->failingMessages);
    }

    private function makeTopFailing(int $failingCount): TopFailingSenderResult
    {
        return new TopFailingSenderResult(
            senderId: 'sender-abc',
            displayLabel: 'mailchimp.com',
            sourceIp: '198.51.100.10',
            domainId: 'domain-1',
            failingMessageCount: $failingCount,
        );
    }
}
