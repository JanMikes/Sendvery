<?php

declare(strict_types=1);

namespace App\Services;

use App\Results\DmarcPolicyAdvisorResult;
use App\Value\DmarcPolicy;

/**
 * Thin testable wrapper around {@see DmarcPolicyAdvisorResult::forDomain}.
 * Lives as a service (not a static call) so controllers can autowire it and
 * future enhancements (logging eligibility flips, emitting analytics events)
 * have a natural home without rippling through every call site.
 */
final readonly class DmarcPolicyAdvisor
{
    public function adviseFor(DmarcPolicy $current, float $passRate, int $reportsCount): DmarcPolicyAdvisorResult
    {
        return DmarcPolicyAdvisorResult::forDomain($current, $passRate, $reportsCount);
    }
}
