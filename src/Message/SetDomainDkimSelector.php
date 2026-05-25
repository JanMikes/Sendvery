<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * TASK-146 — Set (or clear) the per-domain DKIM selector preference.
 *
 * Pass `null` (or empty after the handler normalises) to revert to the
 * brute-force fallback over `DkimSelectorRegistry::PROVIDER_SELECTORS`.
 */
final readonly class SetDomainDkimSelector
{
    public function __construct(
        public UuidInterface $domainId,
        public string $teamId,
        public ?string $selector,
    ) {
    }
}
