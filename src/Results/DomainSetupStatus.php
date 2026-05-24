<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\DomainHealthFilter;

/**
 * Aggregated setup verdict for one domain. Powers both the one-line status
 * banner (TASK-067, via `severity` + `headline` + optional CTA) and the
 * expanded per-protocol checklist below it (TASK-080, via `protocols`).
 *
 * Severity reuses {@see DomainHealthFilter} so the banner tone matches the
 * domain-list severity glyph from TASK-066 verbatim: Healthy → success,
 * Attention → warning, Unverified → error.
 */
final readonly class DomainSetupStatus
{
    /**
     * @param list<ProtocolSetupStatus> $protocols
     */
    public function __construct(
        public DomainHealthFilter $severity,
        public string $headline,
        public ?string $ctaLabel,
        public ?string $ctaRoute,
        public ?string $ctaFragment,
        public array $protocols,
    ) {
    }
}
