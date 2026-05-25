<?php

declare(strict_types=1);

namespace App\Results\Dns;

use App\Value\Dns\DnsRecordDiffKind;

/**
 * Result of {@see \App\Services\Dns\DnsRecordDiffer::diff} (TASK-127).
 *
 * Holds the previous + current raw record text (escaped + rendered verbatim
 * inside the expander) plus the token-level diff segments rendered inline
 * by `templates/components/_dns_record_diff.html.twig`. `hasChanges()`
 * exists so the template can fall back to a plain `code` block when, by
 * the time we computed the diff, both inputs were equal — defence in depth
 * against a controller wiring bug that calls the differ for an unchanged row.
 */
final readonly class DnsRecordDiff
{
    /**
     * @param list<DnsRecordDiffSegment> $segments
     */
    public function __construct(
        public string $previousRecord,
        public string $currentRecord,
        public array $segments,
    ) {
    }

    public function hasChanges(): bool
    {
        foreach ($this->segments as $segment) {
            if (DnsRecordDiffKind::Unchanged !== $segment->kind) {
                return true;
            }
        }

        return false;
    }
}
