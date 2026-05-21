<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\DomainVerificationStatusResult;
use App\Value\DomainVerificationSeverity;
use Psr\Clock\ClockInterface;

final readonly class DomainVerificationEvaluator
{
    private const string SETTLING_WINDOW = '-24 hours';
    private const string NO_REPORT_WAIT = '+48 hours';
    private const int SUSTAINED_FAILURE_THRESHOLD = 2;

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function severity(DomainVerificationStatusResult $status): DomainVerificationSeverity
    {
        // Never seen DMARC valid → can't ingest anything, regardless of how many
        // checks have run. This is the "you haven't set it up yet" state.
        if (null === $status->dmarcVerifiedAt) {
            return DomainVerificationSeverity::Critical;
        }

        $now = $this->clock->now();
        $settlingDeadline = $now->modify(self::SETTLING_WINDOW);
        $isSettling = $status->dmarcVerifiedAt > $settlingDeadline;

        if ($status->consecutiveDmarcFailures > 0) {
            // Within 24h of first verifying, a missed check is almost always DNS
            // propagation, not a real outage. Don't raise an alarm during that
            // window — surface it as informational only.
            if ($isSettling) {
                return DomainVerificationSeverity::Info;
            }

            // Outside the settling window, a single failure could still be a
            // transient resolver hiccup — only sustained failure warrants Critical.
            if ($status->consecutiveDmarcFailures >= self::SUSTAINED_FAILURE_THRESHOLD) {
                return DomainVerificationSeverity::Critical;
            }

            return DomainVerificationSeverity::Info;
        }

        // Latest check passed. Surface "no reports yet" only after the publisher had
        // time to schedule a digest run — aggregate reports run daily so 48h is the
        // earliest point at which silence stops being normal.
        $reportDeadline = $status->dmarcVerifiedAt->modify(self::NO_REPORT_WAIT);

        if (null === $status->firstReportAt && $now > $reportDeadline) {
            return DomainVerificationSeverity::Warning;
        }

        return DomainVerificationSeverity::Ok;
    }
}
