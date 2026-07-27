<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Results\DomainReportCadenceResult;
use App\Services\IngestionSilenceEvaluator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Silence is judged against the domain's OWN observed reporting rhythm, with a
 * floor underneath it (D4).
 *
 * A domain whose reporters send every six hours is meaningfully broken after a
 * day; a domain on a single weekly reporter is not. A global threshold has to
 * pick one of those to serve and fail the other — it either cries wolf at the
 * weekly reporters or stays quiet for days on the busy ones. The floor exists
 * because aggregate reporting is bursty: a tight measured cadence would
 * otherwise produce a threshold that fires on ordinary jitter.
 */
final class IngestionSilenceEvaluatorTest extends TestCase
{
    private const string NOW = '2026-07-28 09:00:00';

    #[Test]
    public function aDailyReporterIsSilentAfterThreeMissedDays(): void
    {
        $evaluator = new IngestionSilenceEvaluator();

        self::assertTrue(
            $evaluator->isSilent($this->cadence(lastReportHoursAgo: 80, medianGapSeconds: 86400.0), $this->now()),
            'A domain that reported daily and has been quiet for over three days has missed three consecutive arrivals. That is past explaining away as a reporter hiccup.',
        );
    }

    #[Test]
    public function aDailyReporterIsNotSilentAfterOneMissedDay(): void
    {
        $evaluator = new IngestionSilenceEvaluator();

        self::assertFalse(
            $evaluator->isSilent($this->cadence(lastReportHoursAgo: 30, medianGapSeconds: 86400.0), $this->now()),
            'One late daily report is routine. Alerting here would spend the owner attention we need for a real outage.',
        );
    }

    #[Test]
    public function aWeeklyReporterIsJudgedAgainstItsOwnSlowerRhythm(): void
    {
        $evaluator = new IngestionSilenceEvaluator();
        $weekly = 7 * 86400.0;

        self::assertFalse(
            $evaluator->isSilent($this->cadence(lastReportHoursAgo: 24 * 10, medianGapSeconds: $weekly), $this->now()),
            'Ten days of quiet is not yet abnormal for a domain that only ever reports weekly. Measuring it against a daily expectation would manufacture an outage.',
        );
        self::assertTrue(
            $evaluator->isSilent($this->cadence(lastReportHoursAgo: 24 * 22, medianGapSeconds: $weekly), $this->now()),
            'Three missed weekly arrivals is the same evidence as three missed daily ones, just on a longer clock.',
        );
    }

    #[Test]
    public function aVeryChattyDomainStillGetsTheSeventyTwoHourFloor(): void
    {
        $evaluator = new IngestionSilenceEvaluator();
        $hourly = 3600.0;

        self::assertFalse(
            $evaluator->isSilent($this->cadence(lastReportHoursAgo: 20, medianGapSeconds: $hourly), $this->now()),
            'Three missed hourly arrivals is only three hours. Reporters batch unpredictably, so the floor has to stop an ordinary quiet afternoon from reading as an outage.',
        );
        self::assertSame(
            IngestionSilenceEvaluator::MINIMUM_SILENCE_HOURS * 3600,
            $evaluator->silenceThresholdSeconds($this->cadence(lastReportHoursAgo: 1, medianGapSeconds: $hourly)),
            'For any cadence faster than a day, the floor is what applies.',
        );
    }

    #[Test]
    public function aDomainWithOnlyOneReportIsMeasuredAgainstTheFloorAlone(): void
    {
        $evaluator = new IngestionSilenceEvaluator();

        // medianGapSeconds is null: one report means no gap has ever been observed.
        self::assertSame(
            IngestionSilenceEvaluator::MINIMUM_SILENCE_HOURS * 3600,
            $evaluator->silenceThresholdSeconds($this->cadence(lastReportHoursAgo: 100, medianGapSeconds: null)),
            'A single report proves a domain CAN report but says nothing about how often it should. Inventing a cadence here would measure silence against an expectation the data never supported.',
        );
        self::assertTrue(
            $evaluator->isSilent($this->cadence(lastReportHoursAgo: 100, medianGapSeconds: null), $this->now()),
            'The floor still applies, so a domain that reported once and never again is eventually surfaced.',
        );
    }

    #[Test]
    public function theFloorOnlyEverMakesTheEvaluatorQuieter(): void
    {
        $evaluator = new IngestionSilenceEvaluator();

        foreach ([60.0, 3600.0, 86400.0, 7 * 86400.0, 30 * 86400.0] as $gap) {
            $threshold = $evaluator->silenceThresholdSeconds($this->cadence(lastReportHoursAgo: 1, medianGapSeconds: $gap));

            self::assertGreaterThanOrEqual(
                IngestionSilenceEvaluator::MINIMUM_SILENCE_HOURS * 3600,
                $threshold,
                sprintf('A %.0f second cadence must never produce a threshold below the floor, or the floor is not a floor.', $gap),
            );
        }
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function cadence(int $lastReportHoursAgo, ?float $medianGapSeconds): DomainReportCadenceResult
    {
        return new DomainReportCadenceResult(
            domainId: '019fa566-9b01-71d2-bae8-d991cc2f5d40',
            domainName: 'example.com',
            teamId: '019fa566-9b01-71d2-bae8-d991cc2f5d41',
            lastReportAt: $this->now()->modify(sprintf('-%d hours', $lastReportHoursAgo)),
            reportCount: 14,
            medianGapSeconds: $medianGapSeconds,
        );
    }
}
