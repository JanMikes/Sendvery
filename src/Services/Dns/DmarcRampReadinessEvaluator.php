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

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function evaluate(MonitoredDomain $domain, DomainReadinessResult $signals): RampReadinessResult
    {
        $now = $this->clock->now();
        $currentStage = AutoRampStage::fromPolicy($domain->managedPolicyP);
        $daysOfData = $this->daysSince($domain->firstReportAt, $now);
        $cnameVerified = null !== $domain->cnameVerifiedAt;
        $paused = null !== $domain->autoRampPausedAt;
        $dwellSatisfied = null === $domain->lastPolicyChangeAt
            || $this->daysSince($domain->lastPolicyChangeAt, $now) >= self::DWELL_DAYS;

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
        if ($signals->passRate < $minPassRate) {
            $blockingReasons[] = 'pass_rate_below_threshold';
        }
        if ($regressionDetected) {
            $blockingReasons[] = 'authorized_senders_failing';
        }

        $ready = $daysOfData >= $minDays
            && $signals->reportsCount >= $minReports
            && $signals->distinctSources >= $minSources
            && $signals->passRate >= $minPassRate
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
            recommendedNextPolicy: $nextStage->targetPolicy(),
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

    private function daysSince(?\DateTimeImmutable $from, \DateTimeImmutable $now): int
    {
        if (null === $from) {
            return 0;
        }

        return (int) $from->diff($now)->days;
    }
}
