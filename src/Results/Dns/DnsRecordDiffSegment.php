<?php

declare(strict_types=1);

namespace App\Results\Dns;

use App\Value\Dns\DnsRecordDiffKind;

/**
 * One contiguous chunk of rendered diff output for a DNS record (TASK-127).
 *
 * `text` is the raw token verbatim — the template MUST escape it. We never
 * pre-escape here because the diff is rendered through Twig's auto-escape
 * machinery, and double-escaping would surface the user's literal angle
 * brackets as `&amp;lt;`.
 */
final readonly class DnsRecordDiffSegment
{
    public function __construct(
        public string $text,
        public DnsRecordDiffKind $kind,
    ) {
    }
}
