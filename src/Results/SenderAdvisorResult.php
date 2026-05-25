<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\SenderAdvisorSeverity;

/**
 * Verdict from {@see \App\Services\SenderAuthorizationAdvisor} for one sender
 * row on the Sender Inventory page (TASK-092). Pure render-time DTO consumed
 * by {@see \App\Twig\Components\SenderActionCallout} — the template branches
 * on `severity` and never re-derives any rule from the underlying inputs.
 *
 * `primaryActionLabel` is null only for `Monitor` and `None`; the
 * SenderActionCallout component returns nothing for those branches so the
 * field is documentation-only there.
 */
final readonly class SenderAdvisorResult
{
    public function __construct(
        public string $senderId,
        public SenderAdvisorSeverity $severity,
        public string $reasonText,
        public ?string $primaryActionLabel,
    ) {
    }

    public static function none(string $senderId): self
    {
        return new self(
            senderId: $senderId,
            severity: SenderAdvisorSeverity::None,
            reasonText: '',
            primaryActionLabel: null,
        );
    }
}
