<?php

declare(strict_types=1);

namespace App\Value;

/**
 * A single row on the onboarding setup checklist surface on the dashboard
 * overview. All copy + the CTA target are baked in by the resolver so the
 * template stays presentation-only.
 */
final readonly class SetupChecklistStep
{
    /**
     * @param array<string, string> $actionRouteParams
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $actionRoute,
        public string $actionLabel,
        public array $actionRouteParams,
        public bool $isComplete,
    ) {
    }
}
