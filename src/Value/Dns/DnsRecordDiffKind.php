<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * Per-segment kind for {@see \App\Results\Dns\DnsRecordDiffSegment}.
 *
 * - Unchanged: token survived between previous and current observations.
 * - Removed:   token belonged to the previous record only — renders struck through.
 * - Added:     token belongs to the current record only — renders bolded/highlighted.
 */
enum DnsRecordDiffKind: string
{
    case Unchanged = 'unchanged';
    case Added = 'added';
    case Removed = 'removed';
}
