<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\SetupChecklistStep;

/**
 * Output of {@see \App\Services\SetupChecklistResolver}: the prepared list of
 * onboarding steps plus the precomputed flags the template needs to decide
 * whether to render the card at all.
 */
final readonly class SetupChecklistResult
{
    /**
     * @param list<SetupChecklistStep> $steps
     */
    public function __construct(
        public array $steps,
        public int $completedCount,
        public int $totalCount,
        public bool $isVisible,
        public bool $isFullyComplete,
    ) {
    }
}
