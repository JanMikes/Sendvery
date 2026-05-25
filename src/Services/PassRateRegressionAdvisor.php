<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\PassRateAggregate;
use App\Results\PassRateRegressionResult;
use App\Results\TopFailingSenderResult;
use App\Value\PassRateRegressionSeverity;

/**
 * Picks the pass-rate regression / improvement banner shown above the table
 * on `/app/reports` (TASK-093). Pure deterministic computation over a 7-day
 * {@see PassRateAggregate}, a 30-day baseline aggregate, and an optional
 * top-failing-sender (single row).
 *
 * Eligibility hard-rules (locked by tests):
 *  - Regression fires when 7d pass rate dropped by at least 10 percentage
 *    points from the 30d baseline AND the baseline was at least 70% (we
 *    don't want to compound the bad-news for already-broken setups) AND
 *    we have at least 20 reports in the 7d window (small-sample noise floor)
 *    AND BOTH windows clear the {@see self::MIN_SAMPLE_SIZE} minimum-volume
 *    floor introduced by TASK-109.
 *  - Improvement fires when 7d pass rate climbed by at least 10pp from a
 *    baseline below 90% (we don't want "celebrate" copy when the user was
 *    already at 99% and crawled to 99.5%) AND the same 20-report floor AND
 *    the same {@see self::MIN_SAMPLE_SIZE} floor on BOTH windows.
 *  - Stable otherwise — no banner.
 *
 * Hard rule explicitly preserved: this advisor reads pass rates and surfaces
 * a sender — it does NOT propose ingestion-path changes. The template that
 * renders this banner must never CTA toward "connect a mailbox" or "switch
 * to DNS"; those decisions belong on `/app/mailboxes` per TASK-090.
 */
final readonly class PassRateRegressionAdvisor
{
    private const float DELTA_THRESHOLD_PP = 10.0;
    private const float REGRESSION_MIN_BASELINE = 70.0;
    private const float IMPROVEMENT_MAX_BASELINE = 90.0;
    private const int MIN_REPORTS_7D = 20;

    /**
     * Minimum-volume floor applied to BOTH the current 7-day window AND the
     * prior baseline window (TASK-109). The pre-existing
     * {@see self::MIN_REPORTS_7D} = 20 floor only guarded against a literal
     * handful of reports; this larger floor guards against the wider band
     * where a 10pp swing is still within random variance for typical
     * pass-rate distributions.
     *
     * A 10pp swing on <50 reports is within random variance for typical
     * pass-rate distributions. 50 is a round-number floor — safer than 20
     * (which still allows e.g. 8 of 50 fails → 16% baseline pass rate to
     * look like a regression), more permissive than 100 (which would
     * suppress the banner for many real low-volume teams). Re-evaluate if
     * low-volume false positives or high-volume late-fires become a pattern.
     */
    private const int MIN_SAMPLE_SIZE = 50;

    public function advise(
        PassRateAggregate $window7d,
        PassRateAggregate $baseline30d,
        ?TopFailingSenderResult $topFailingSender,
    ): PassRateRegressionResult {
        // Small-sample suppression: 20 reports is the noise floor. Below this
        // a single big sender flips the team-wide rate enough to look like a
        // regression that doesn't actually mean anything. Falls into Stable
        // (no banner) rather than emitting low-confidence guidance.
        if ($window7d->reportCount < self::MIN_REPORTS_7D) {
            return PassRateRegressionResult::stable(
                currentRate7d: $window7d->passRate,
                baselineRate30d: $baseline30d->passRate,
            );
        }

        // Identical small-sample rule for the 30d baseline: if we don't have
        // enough history to call something a "baseline", every comparison is
        // suspect. Skip the verdict.
        if ($baseline30d->reportCount < self::MIN_REPORTS_7D) {
            return PassRateRegressionResult::stable(
                currentRate7d: $window7d->passRate,
                baselineRate30d: $baseline30d->passRate,
            );
        }

        // TASK-109 minimum-volume floor: ADDITIONAL to the ≥10pp delta rule,
        // not a replacement. Suppress when EITHER window has fewer than
        // MIN_SAMPLE_SIZE reports — random variance at that volume swallows
        // the 10pp signal and the banner would be a false positive on a
        // low-traffic domain.
        if ($window7d->reportCount < self::MIN_SAMPLE_SIZE) {
            return PassRateRegressionResult::stable(
                currentRate7d: $window7d->passRate,
                baselineRate30d: $baseline30d->passRate,
            );
        }

        if ($baseline30d->reportCount < self::MIN_SAMPLE_SIZE) {
            return PassRateRegressionResult::stable(
                currentRate7d: $window7d->passRate,
                baselineRate30d: $baseline30d->passRate,
            );
        }

        $delta = $window7d->passRate - $baseline30d->passRate;

        if ($this->isRegression($delta, $baseline30d->passRate)) {
            return new PassRateRegressionResult(
                severity: PassRateRegressionSeverity::Regression,
                currentRate7d: $window7d->passRate,
                baselineRate30d: $baseline30d->passRate,
                delta: $delta,
                topFailingSender: $topFailingSender,
                totalFailingMessages7d: $window7d->failingMessages,
            );
        }

        if ($this->isImprovement($delta, $baseline30d->passRate)) {
            return new PassRateRegressionResult(
                severity: PassRateRegressionSeverity::Improvement,
                currentRate7d: $window7d->passRate,
                baselineRate30d: $baseline30d->passRate,
                delta: $delta,
                topFailingSender: null,
                totalFailingMessages7d: 0,
            );
        }

        return PassRateRegressionResult::stable(
            currentRate7d: $window7d->passRate,
            baselineRate30d: $baseline30d->passRate,
        );
    }

    private function isRegression(float $delta, float $baseline): bool
    {
        // Note: -10.0 INCLUSIVE — a clean -10pp drop must fire, per the
        // boundary test "exactlyAtTenPpDropFires". Bail when the baseline is
        // already broken; bad-news compounding doesn't help the user.
        if ($delta > -self::DELTA_THRESHOLD_PP) {
            return false;
        }

        return $baseline >= self::REGRESSION_MIN_BASELINE;
    }

    private function isImprovement(float $delta, float $baseline): bool
    {
        // +10pp INCLUSIVE for symmetry with the regression boundary.
        if ($delta < self::DELTA_THRESHOLD_PP) {
            return false;
        }

        // Suppress "celebrate" copy when the user was already healthy — going
        // from 95% to 99% is great but doesn't warrant a banner.
        return $baseline < self::IMPROVEMENT_MAX_BASELINE;
    }
}
