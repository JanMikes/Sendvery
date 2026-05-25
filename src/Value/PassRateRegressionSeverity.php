<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Classification for the team-wide pass-rate verdict surfaced on `/app/reports`
 * (TASK-093). The three cases are mutually exclusive — the
 * {@see \App\Services\PassRateRegressionAdvisor} picks one (Stable means
 * "nothing worth showing") so the template never has to merge two banners on
 * the same surface.
 */
enum PassRateRegressionSeverity: string
{
    case Regression = 'regression';
    case Improvement = 'improvement';
    case Stable = 'stable';
}
