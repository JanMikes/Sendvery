<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\ProtocolState;

/**
 * One row in the per-domain setup checklist (TASK-080). Bundles the canonical
 * display name (SPF / DKIM / DMARC / MX), the resolved state, the one-line
 * status copy shown next to the row glyph, and — when the state is not
 * `Configured` — a concrete next-step instruction plus the deep-link anchor on
 * the per-domain DNS health page.
 */
final readonly class ProtocolSetupStatus
{
    public function __construct(
        public string $name,
        public ProtocolState $state,
        public string $statusLine,
        public ?string $nextStep,
        public ?string $kbSlug,
        public string $healthAnchor,
    ) {
    }
}
