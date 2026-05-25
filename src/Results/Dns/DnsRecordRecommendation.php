<?php

declare(strict_types=1);

namespace App\Results\Dns;

use App\Value\Dns\DnsRecordCategory;

/**
 * Result DTO from {@see \App\Services\Dns\DnsRecordRecommender} (TASK-095).
 * One DnsRecordRecommendation per category that has actionable guidance — the
 * template iterates over the four categories on `/app/domains/{id}/health` and
 * renders the right card variant per recommendation shape:
 *
 *  - `recommendedValue !== null` → `<twig:DnsRecordInstruction>` (publish this exact record)
 *  - `recommendedValue === null` → text-only how-to card (do this thing, no copyable record)
 *
 * The `severity` field is intentionally cosmetic on this surface — every
 * recommendation already implies a fix is needed, and the existing per-protocol
 * score bar / colour conveys the urgency. The field lives here so future
 * features (sorting, grouping, KB cross-references) have a hook to lean on.
 */
final readonly class DnsRecordRecommendation
{
    public function __construct(
        public DnsRecordCategory $category,
        public string $severity,
        public string $recordType,
        public string $recordHost,
        public ?string $recommendedValue,
        public string $whatText,
        public string $whyText,
    ) {
    }
}
