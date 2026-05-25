<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\MailboxHealthSeverity;

/**
 * Advisor output for the per-mailbox health card on `/app/mailboxes/{id}`
 * (TASK-094 / TASK-108). Pure render-time DTO — every field is consumed by
 * `<twig:MailboxHealthAdvisorCard>` and nothing else.
 *
 * `primaryAction` is required; `secondaryAction` is optional. Both wrap label
 * + route + params + glyph onto a {@see MailboxHealthAdvisorAction} so the
 * advisor can vary the WHOLE CTA (including its visual glyph) per scenario
 * atomically. The earlier shape with parallel `*Label` / `*Route` /
 * `*RouteParams` scalar fields forced the template to derive the glyph from
 * the route string, which broke as soon as two different intents (silent
 * vs. disconnect) shared the same route name — that's exactly what TASK-108
 * needs to do for the silent-bound-to-scenario-(b) branch.
 */
final readonly class MailboxHealthAdvisorResult
{
    public function __construct(
        public MailboxHealthSeverity $severity,
        public string $reasonText,
        public MailboxHealthAdvisorAction $primaryAction,
        public ?MailboxHealthAdvisorAction $secondaryAction = null,
    ) {
    }
}
