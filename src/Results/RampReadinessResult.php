<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\Dns\AutoRampStage;
use App\Value\Dns\ManagedDmarcPolicy;

/**
 * The auto-ramp readiness verdict for a managed domain, produced by
 * DmarcRampReadinessEvaluator. `ready` means the mail data qualifies for the
 * next tier; `eligibleForNextTier` means it qualifies AND the operational gates
 * (verified CNAME, 7-day dwell, not paused) are satisfied, so it is safe to
 * advance right now. `blockingReasons` explains any gap for the dashboard hint.
 */
final readonly class RampReadinessResult
{
    /** @param list<string> $blockingReasons */
    public function __construct(
        public AutoRampStage $currentStage,
        public ?ManagedDmarcPolicy $recommendedNextPolicy,
        public bool $ready,
        public bool $eligibleForNextTier,
        public bool $regressionDetected,
        public bool $cnameVerified,
        public int $daysOfData,
        public float $passRate,
        public int $distinctSources,
        public array $blockingReasons,
    ) {
    }
}
