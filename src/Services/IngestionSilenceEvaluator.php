<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\DomainReportCadenceResult;

/**
 * Decides whether a domain's silence has gone on long enough to be worth
 * telling its owner about (D4).
 *
 * The rule: silent longer than max(3 x its own observed median gap, 72h).
 *
 * WHY A MULTIPLE OF THE DOMAIN'S OWN CADENCE: a domain whose reporters send
 * every 6 hours is meaningfully broken after a day; a domain on a weekly
 * reporter is not. One global threshold would either spam the first or ignore
 * the second. Three consecutive missed arrivals is the smallest number that
 * rules out a single reporter's transient hiccup.
 *
 * WHY A 72h FLOOR UNDERNEATH IT: aggregate reporting is bursty and reporters
 * batch unpredictably, so a tight observed cadence can produce a very short
 * threshold that fires on normal jitter. 72h is three missed days for the daily
 * reporters that dominate real traffic. The floor only ever makes the evaluator
 * quieter, never louder.
 *
 * WHAT THIS DELIBERATELY DOES NOT DECIDE: whether our own pipeline is healthy.
 * That gate lives with the caller, and silence must never be reported to a
 * customer while our side is unproven — see CheckIngestionHealthCommand.
 */
final readonly class IngestionSilenceEvaluator
{
    /**
     * Three consecutive missed arrivals before we say anything.
     */
    public const int MISSED_ARRIVALS_BEFORE_ALERT = 3;

    /**
     * Never call a domain silent sooner than this, however tight its measured
     * cadence looks.
     */
    public const int MINIMUM_SILENCE_HOURS = 72;

    public function isSilent(DomainReportCadenceResult $cadence, \DateTimeImmutable $now): bool
    {
        return $cadence->lastReportAt <= $now->modify(sprintf('-%d seconds', $this->silenceThresholdSeconds($cadence)));
    }

    /**
     * How long this domain may stay quiet before it counts as silent.
     *
     * A domain with only one report has no observed gap, so it is measured
     * against the floor alone rather than against an invented cadence.
     */
    public function silenceThresholdSeconds(DomainReportCadenceResult $cadence): int
    {
        $floor = self::MINIMUM_SILENCE_HOURS * 3600;

        if (null === $cadence->medianGapSeconds) {
            return $floor;
        }

        $observed = (int) round($cadence->medianGapSeconds * self::MISSED_ARRIVALS_BEFORE_ALERT);

        return max($floor, $observed);
    }
}
