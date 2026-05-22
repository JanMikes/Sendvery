<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\NextAction;

/**
 * Resolver output: a fully-prepared next-action card for the dashboard
 * overview. All copy and the CTA target are baked in here so the template
 * is presentation-only.
 */
final readonly class NextActionResult
{
    /**
     * @param array<string, string> $ctaRouteParams
     */
    public function __construct(
        public NextAction $actionKey,
        public string $title,
        public string $description,
        public string $ctaLabel,
        public string $ctaRoute,
        public array $ctaRouteParams,
        public string $severity,
    ) {
    }
}
