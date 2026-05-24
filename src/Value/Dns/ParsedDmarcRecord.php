<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * Pure data carrier for the subset of DMARC TXT-record tags TASK-100 needs to
 * drive scenario-aware ingestion recommendations. Lives in the `Dns` namespace
 * to avoid colliding with `App\Value\ParsedDmarcRecord` (a separate DTO that
 * describes one row of a parsed DMARC aggregate REPORT, not the published TXT
 * record).
 *
 * Populated by {@see \App\Services\Dns\DmarcRecordParser}.
 */
final readonly class ParsedDmarcRecord
{
    /**
     * @param list<string> $ruaAddresses email addresses extracted from `rua=`,
     *                                   already stripped of the `mailto:` prefix
     * @param list<string> $rufAddresses ditto, but from the `ruf=` tag
     */
    public function __construct(
        public ?string $policy,
        public array $ruaAddresses,
        public array $rufAddresses,
        public ?int $pct,
    ) {
    }
}
