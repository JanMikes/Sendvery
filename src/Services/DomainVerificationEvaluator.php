<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\DomainVerificationStatusResult;
use App\Value\DomainVerificationSeverity;
use Psr\Clock\ClockInterface;

final readonly class DomainVerificationEvaluator
{
    private const string NO_REPORT_WAIT = '+48 hours';

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    public function severity(DomainVerificationStatusResult $status): DomainVerificationSeverity
    {
        // Never seen DMARC valid → can't ingest anything.
        if (null === $status->dmarcVerifiedAt) {
            return DomainVerificationSeverity::Critical;
        }

        // Was valid before but the latest check says it's gone now.
        if (!$status->dmarcCurrentlyValid) {
            return DomainVerificationSeverity::Critical;
        }

        // DMARC valid but no reports have arrived after the publisher had time
        // to schedule a digest run. Aggregate reports run daily so 48h is the
        // earliest point at which "still nothing" stops being normal.
        $now = $this->clock->now();
        $deadline = $status->dmarcVerifiedAt->modify(self::NO_REPORT_WAIT);

        if (null === $status->firstReportAt && $now > $deadline) {
            return DomainVerificationSeverity::Warning;
        }

        return DomainVerificationSeverity::Ok;
    }
}
