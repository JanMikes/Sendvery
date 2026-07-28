<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Entity\MonitoredDomain;
use App\Results\DomainReadinessResult;
use App\Results\RampReadinessResult;
use App\Value\Dns\AutoRampStage;
use Psr\Clock\ClockInterface;

/**
 * Decides whether a managed domain may safely advance to the next DMARC tier,
 * using STRICTER thresholds than the manual advisor (DEC-058b). The current
 * stage is always derived from the published policy. Every gate is re-checked
 * here so a forged request can't skip readiness — the advance handler and the
 * auto-ramp cron both route through this.
 */
final readonly class DmarcRampReadinessEvaluator
{
    private const float NONE_TO_QUARANTINE_PASS_RATE = 95.0;
    private const int NONE_TO_QUARANTINE_DAYS = 30;
    private const int NONE_TO_QUARANTINE_MIN_REPORTS = 3;
    private const int NONE_TO_QUARANTINE_MIN_SOURCES = 2;
    private const float QUARANTINE_TO_REJECT_PASS_RATE = 99.0;
    private const int QUARANTINE_TO_REJECT_DAYS = 60;
    private const int DWELL_DAYS = 7;

    /**
     * How old `cnameVerifiedAt` may be and still count as "the CNAME is live".
     *
     * `cnameVerifiedAt` is refreshed by the 03:00 `sendvery:dns:check-all` sweep
     * and cleared the moment a sweep cannot see the CNAME, so on a healthy host
     * the timestamp the 05:30 ramp reads is ~2.5 hours old. 26 hours is one full
     * sweep cycle plus a 2-hour grace for a slow or slightly late run: a single
     * on-time sweep always satisfies it, and a SKIPPED sweep never does.
     *
     * Existence alone is not evidence of liveness. Without an age bound, a
     * timestamp from a sweep that failed, was skipped, or is still running reads
     * as "verified right now" — and this gate is the only thing standing between
     * a customer and `p=reject` on a `_dmarc` CNAME that no longer resolves to
     * us, i.e. full enforcement with no policy actually being served. Failing
     * closed costs at most one day of ramp progress; failing open rejects real
     * mail.
     */
    private const int CNAME_VERIFICATION_MAX_AGE_HOURS = 26;

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function evaluate(MonitoredDomain $domain, DomainReadinessResult $signals): RampReadinessResult
    {
        $now = $this->clock->now();
        $currentStage = AutoRampStage::fromPolicy($domain->managedPolicyP);
        $daysOfData = $this->daysSince($domain->firstReportAt, $now);
        $cnameVerified = $this->cnameVerificationIsFresh($domain->cnameVerifiedAt, $now);
        $paused = null !== $domain->autoRampPausedAt;
        // A missing `lastPolicyChangeAt` is the ABSENCE of a recorded change, not
        // proof that a week has passed since the last one. Treating it as
        // satisfied skipped the mandatory 7-day dwell outright for any legacy or
        // backfilled row — exactly the rows we know least about. Every live
        // managed domain gets the column stamped by
        // `MonitoredDomain::enableManagedDmarc()` and again by every policy
        // change, so failing closed here blocks only rows whose history we
        // cannot see, and any subsequent policy change unblocks them.
        $dwellSatisfied = null !== $domain->lastPolicyChangeAt
            && $this->daysSince($domain->lastPolicyChangeAt, $now) >= self::DWELL_DAYS;

        // An authorized sender failing alignment is the regression signal — at an
        // enforcing tier it means real mail is at risk of being blocked.
        $regressionDetected = $signals->authorizedFailureVolume > 0;

        $nextStage = $currentStage->next();
        $blockingReasons = [];

        // Reject is terminal — there is no tighter tier to recommend.
        if (null === $nextStage || AutoRampStage::Complete === $nextStage) {
            return new RampReadinessResult(
                currentStage: $currentStage,
                recommendedNextPolicy: null,
                ready: false,
                eligibleForNextTier: false,
                regressionDetected: $regressionDetected,
                cnameVerified: $cnameVerified,
                daysOfData: $daysOfData,
                passRate: $signals->passRate,
                distinctSources: $signals->distinctSources,
                blockingReasons: ['already_at_full_enforcement'],
            );
        }

        [$minPassRate, $minDays, $minReports, $minSources] = match ($currentStage) {
            AutoRampStage::Quarantine => [self::QUARANTINE_TO_REJECT_PASS_RATE, self::QUARANTINE_TO_REJECT_DAYS, 0, 0],
            default => [self::NONE_TO_QUARANTINE_PASS_RATE, self::NONE_TO_QUARANTINE_DAYS, self::NONE_TO_QUARANTINE_MIN_REPORTS, self::NONE_TO_QUARANTINE_MIN_SOURCES],
        };

        if ($daysOfData < $minDays) {
            $blockingReasons[] = 'thin_data';
        }
        if ($signals->reportsCount < $minReports) {
            $blockingReasons[] = 'too_few_reports';
        }
        if ($signals->distinctSources < $minSources) {
            $blockingReasons[] = 'too_few_sources';
        }

        // An absent pass rate is NOT a low pass rate, and it is emphatically not
        // a qualifying one. Written as an explicit null arm rather than left to
        // PHP's `null < 99.0` (which happens to be true, i.e. safe, but is an
        // accident of loose comparison): the shape one refactor away is
        // `null !== $passRate && $passRate < $minPassRate`, which SKIPS the block
        // on null and advances a domain we have measured nothing about straight
        // to p=reject. The separate reason also stops the card telling a user
        // their rate is "below threshold" when no rate exists.
        $passRateQualifies = null !== $signals->passRate && $signals->passRate >= $minPassRate;
        if (null === $signals->passRate) {
            $blockingReasons[] = 'no_pass_rate_data';
        } elseif (!$passRateQualifies) {
            $blockingReasons[] = 'pass_rate_below_threshold';
        }

        if ($regressionDetected) {
            $blockingReasons[] = 'authorized_senders_failing';
        }

        $ready = $daysOfData >= $minDays
            && $signals->reportsCount >= $minReports
            && $signals->distinctSources >= $minSources
            && $passRateQualifies
            && !$regressionDetected;

        if (!$cnameVerified) {
            $blockingReasons[] = 'cname_not_verified';
        }
        if (!$dwellSatisfied) {
            $blockingReasons[] = 'dwell_not_satisfied';
        }
        if ($paused) {
            $blockingReasons[] = 'auto_ramp_paused';
        }

        $eligibleForNextTier = $ready && $cnameVerified && $dwellSatisfied && !$paused;

        return new RampReadinessResult(
            currentStage: $currentStage,
            recommendedNextPolicy: $nextStage->targetPolicy($domain->currentManagedPolicy()),
            ready: $ready,
            eligibleForNextTier: $eligibleForNextTier,
            regressionDetected: $regressionDetected,
            cnameVerified: $cnameVerified,
            daysOfData: $daysOfData,
            passRate: $signals->passRate,
            distinctSources: $signals->distinctSources,
            blockingReasons: $blockingReasons,
        );
    }

    /**
     * "Was the CNAME confirmed recently enough to act on?" — deliberately the
     * same shape as the `!$cnameVerified` rail it feeds, so a stale timestamp and
     * a missing one produce the identical `cname_not_verified` block. A future
     * timestamp (clock skew on a restored backup) is also treated as fresh: the
     * gate is about staleness, and inventing a second failure mode for a clock
     * we cannot fix would only freeze ramps for the wrong reason.
     */
    private function cnameVerificationIsFresh(?\DateTimeImmutable $verifiedAt, \DateTimeImmutable $now): bool
    {
        if (null === $verifiedAt) {
            return false;
        }

        return $verifiedAt >= $now->modify(sprintf('-%d hours', self::CNAME_VERIFICATION_MAX_AGE_HOURS));
    }

    private function daysSince(?\DateTimeImmutable $from, \DateTimeImmutable $now): int
    {
        if (null === $from) {
            return 0;
        }

        return (int) $from->diff($now)->days;
    }
}
