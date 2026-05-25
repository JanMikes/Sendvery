<?php

declare(strict_types=1);

namespace App\Results;

/**
 * One actionable phrase rendered inside the `/app` hero
 * {@see AttentionSummaryResult}.
 *
 * Each item collapses one signal type (critical alerts / unverified domains /
 * quarantine pile-up) into a clickable phrase: a short visible label, the
 * deep-link route + params, and the tone class the template should attach to
 * the anchor.
 *
 * Kept as a tiny readonly DTO instead of an enum so the resolver can compose
 * the label string (which includes the count) without re-running pluralisation
 * logic at the template.
 *
 * @phpstan-type RouteParams array<string, scalar>
 */
final readonly class AttentionItem
{
    /**
     * @param RouteParams $routeParams
     */
    public function __construct(
        public string $label,
        public string $route,
        public array $routeParams,
        public string $colorClass,
    ) {
    }
}
