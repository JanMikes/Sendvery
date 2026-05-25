<?php

declare(strict_types=1);

namespace App\Results;

/**
 * One CTA on the per-mailbox health advisor card (TASK-108). Bundling the
 * label, route, params, and glyph onto a single value object lets the advisor
 * vary the WHOLE action atomically per scenario — previously the template had
 * to derive a glyph from `primaryActionRoute` string-matching, which silently
 * broke any time a branch reused a route under a different intent (e.g. both
 * the silent and disconnect CTAs landing on `dashboard_mailboxes`, since
 * fixed by TASK-133 which gave disconnect its own POST endpoint).
 *
 * `glyph` is a free-form key that the template maps to an inline SVG: `search`
 * (DNS lookup), `unlink` (disconnect), `pencil` (publish a record), `retest`
 * (re-test connection), `quarantine` (open quarantine list). Keeping it a
 * string instead of an enum keeps the template's lookup table the single
 * source of truth — adding a new glyph is a template-only change.
 *
 * `routeParams` is `array<string, string>` because every dashboard route
 * Sendvery generates currently takes string-only params (UUIDs or slugs).
 */
final readonly class MailboxHealthAdvisorAction
{
    /**
     * @param array<string, string> $routeParams
     */
    public function __construct(
        public string $label,
        public string $route,
        public array $routeParams,
        public string $glyph,
    ) {
    }
}
