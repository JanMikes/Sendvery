<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\MailboxHealthSeverity;

/**
 * Advisor output for the per-mailbox health card on `/app/mailboxes/{id}`
 * (TASK-094). Pure render-time DTO — every field is consumed by
 * `<twig:MailboxHealthAdvisorCard>` and nothing else. The two route-name +
 * params pairs let the template build URLs without knowing which advisory
 * branch fired; route-params is `array<string, string>` because every
 * dashboard route Sendvery generates currently takes string-only params
 * (UUIDs or slugs) — keeping the type narrow avoids accidental scalar drift.
 */
final readonly class MailboxHealthAdvisorResult
{
    /**
     * @param array<string, string> $primaryActionRouteParams
     * @param array<string, string> $secondaryActionRouteParams
     */
    public function __construct(
        public MailboxHealthSeverity $severity,
        public string $reasonText,
        public string $primaryActionLabel,
        public string $primaryActionRoute,
        public array $primaryActionRouteParams,
        public ?string $secondaryActionLabel = null,
        public ?string $secondaryActionRoute = null,
        public array $secondaryActionRouteParams = [],
    ) {
    }
}
