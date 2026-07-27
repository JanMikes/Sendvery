<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\SetupChecklistDomain;
use App\Value\SetupChecklistStep;

/**
 * Output of {@see \App\Services\SetupChecklistResolver}: the prepared list of
 * onboarding steps plus the precomputed flags the template needs to decide
 * whether to render the card at all.
 *
 * `focusDomainName` is the domain the open steps are about, so the card can say
 * WHICH domain it is asking the user to set up instead of leaving them to guess.
 * Null only while the team has no domains at all, where step 1 is the only open
 * step and there is nothing to name yet.
 */
final readonly class SetupChecklistResult
{
    /**
     * @param list<SetupChecklistStep>   $steps
     * @param list<SetupChecklistDomain> $otherUnfinishedDomains further domains still missing DMARC; empty once the DMARC step is ticked
     */
    public function __construct(
        public array $steps,
        public int $completedCount,
        public int $totalCount,
        public bool $isVisible,
        public bool $isFullyComplete,
        public ?string $focusDomainName = null,
        public array $otherUnfinishedDomains = [],
    ) {
    }
}
