<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Results\Dns\DnsRecordDiff;
use App\Results\Dns\DnsRecordDiffSegment;
use App\Value\Dns\DnsRecordDiffKind;
use App\Value\DnsCheckType;

/**
 * Token-level diff of two raw DNS record observations (TASK-127).
 *
 * The DNS history page used to render changes as two opaque blobs labelled
 * "Previous" + "Current". Even small flips like `p=none` → `p=quarantine`
 * forced the user to scan both strings character by character — exactly the
 * trust-eroding "make the human do the work" failure mode TASK-127 fixes.
 *
 * Tokenisation is per-protocol because each record type has its own grammar:
 *
 *  - DMARC: `key=value; key=value; …`. Tokens are tag pairs split on `;`.
 *           A tag flip (same key, different value) renders as `removed → added`
 *           adjacent in the output so the user reads the change as one beat.
 *  - SPF:   whitespace-separated mechanisms (`v=spf1`, `include:`, `ip4:`, `~all`).
 *           Tokens are diffed by exact string equality — there's no key/value
 *           pairing on SPF mechanisms, so "include:_spf.a" vs "include:_spf.b"
 *           always renders as one removed + one added (not a flip), which
 *           matches how the user actually edits SPF.
 *  - DKIM:  treated as a single opaque blob. Inside `p=<long base64>` there's
 *           nothing useful to highlight — the public key body is one unit, and
 *           any change means the whole record turned over. Show the entire
 *           previous as removed and the entire current as added.
 *  - MX:    line-separated `priority value` rows. Diff by full-line equality.
 *           This deliberately renders a priority flip as removed+added rather
 *           than trying to align hostnames across lines.
 *
 * The output is rendered by `templates/components/_dns_record_diff.html.twig`
 * via Twig auto-escape; this service never returns HTML.
 */
final readonly class DnsRecordDiffer
{
    /**
     * Build a token-level diff between two raw record observations.
     *
     * Both `$previousRecord` and `$currentRecord` come straight from
     * `dns_check_result.raw_record`, which is nullable when a lookup
     * resolved to "no record". Treat null + empty string identically —
     * both mean "nothing was there".
     */
    public function diff(
        DnsCheckType $type,
        ?string $previousRecord,
        ?string $currentRecord,
    ): DnsRecordDiff {
        $previous = $previousRecord ?? '';
        $current = $currentRecord ?? '';

        $segments = match ($type) {
            DnsCheckType::Dmarc => $this->diffDmarc($previous, $current),
            DnsCheckType::Spf => $this->diffSpf($previous, $current),
            DnsCheckType::Mx => $this->diffMx($previous, $current),
            DnsCheckType::Dkim => $this->diffOpaque($previous, $current),
        };

        return new DnsRecordDiff(
            previousRecord: $previous,
            currentRecord: $current,
            segments: $segments,
        );
    }

    /**
     * @return list<DnsRecordDiffSegment>
     */
    private function diffDmarc(string $previous, string $current): array
    {
        $previousTags = $this->parseDmarcTags($previous);
        $currentTags = $this->parseDmarcTags($current);

        // Walk keys in the order they first appear (previous first, then any
        // new keys appended). Preserving order matters because the user reads
        // the record left-to-right and expects the diff to read the same way.
        $orderedKeys = [];
        foreach (array_keys($previousTags) as $key) {
            $orderedKeys[$key] = true;
        }
        foreach (array_keys($currentTags) as $key) {
            $orderedKeys[$key] = true;
        }

        $segments = [];
        $first = true;
        foreach (array_keys($orderedKeys) as $key) {
            $inPrevious = array_key_exists($key, $previousTags);
            $inCurrent = array_key_exists($key, $currentTags);

            if ($inPrevious && $inCurrent && $previousTags[$key] === $currentTags[$key]) {
                $segments[] = $this->joinSeparator($first);
                $segments[] = new DnsRecordDiffSegment(
                    $this->renderDmarcTag($key, $currentTags[$key]),
                    DnsRecordDiffKind::Unchanged,
                );
                $first = false;

                continue;
            }

            if ($inPrevious) {
                $segments[] = $this->joinSeparator($first);
                $segments[] = new DnsRecordDiffSegment(
                    $this->renderDmarcTag($key, $previousTags[$key]),
                    DnsRecordDiffKind::Removed,
                );
                $first = false;
            }

            if ($inCurrent) {
                $segments[] = $this->joinSeparator($first);
                $segments[] = new DnsRecordDiffSegment(
                    $this->renderDmarcTag($key, $currentTags[$key]),
                    DnsRecordDiffKind::Added,
                );
                $first = false;
            }
        }

        return $this->compact($segments);
    }

    /**
     * @return array<string, string>
     */
    private function parseDmarcTags(string $record): array
    {
        $record = trim($record);
        if ('' === $record) {
            return [];
        }

        $tags = [];
        foreach (explode(';', $record) as $segment) {
            $segment = trim($segment);
            if ('' === $segment) {
                continue;
            }

            $eq = strpos($segment, '=');
            if (false === $eq) {
                // Bare token (no `=`) — preserve it under its own pseudo-key so
                // it still participates in the diff. Real DMARC records won't
                // hit this, but defensive against operator typos.
                $tags[$segment] = '';

                continue;
            }

            $key = trim(substr($segment, 0, $eq));
            $value = trim(substr($segment, $eq + 1));
            $tags[$key] = $value;
        }

        return $tags;
    }

    private function renderDmarcTag(string $key, string $value): string
    {
        return '' === $value ? $key : sprintf('%s=%s', $key, $value);
    }

    /**
     * @return list<DnsRecordDiffSegment>
     */
    private function diffSpf(string $previous, string $current): array
    {
        $previousTokens = $this->tokenizeWhitespace($previous);
        $currentTokens = $this->tokenizeWhitespace($current);

        return $this->diffTokensInOrder($previousTokens, $currentTokens, ' ');
    }

    /**
     * @return list<DnsRecordDiffSegment>
     */
    private function diffMx(string $previous, string $current): array
    {
        // MX raw records arrive as one line per priority+value pair. We split
        // on any newline (incl. `\r\n` from Windows hosts) so a CRLF blob
        // doesn't render the entire record as one big diff.
        $previousLines = $this->tokenizeLines($previous);
        $currentLines = $this->tokenizeLines($current);

        return $this->diffTokensInOrder($previousLines, $currentLines, "\n");
    }

    /**
     * @return list<DnsRecordDiffSegment>
     */
    private function diffOpaque(string $previous, string $current): array
    {
        // DKIM is one big opaque block — `v=DKIM1; k=rsa; p=<long base64>`.
        // Diffing inside the `p=` body would be noise; the operator either
        // rotated the key (whole record turns over) or didn't.
        if ($previous === $current) {
            return '' === $previous
                ? []
                : [new DnsRecordDiffSegment($current, DnsRecordDiffKind::Unchanged)];
        }

        $segments = [];
        if ('' !== $previous) {
            $segments[] = new DnsRecordDiffSegment($previous, DnsRecordDiffKind::Removed);
        }
        if ('' !== $current) {
            if ([] !== $segments) {
                $segments[] = new DnsRecordDiffSegment(' ', DnsRecordDiffKind::Unchanged);
            }
            $segments[] = new DnsRecordDiffSegment($current, DnsRecordDiffKind::Added);
        }

        return $segments;
    }

    /**
     * @return list<string>
     */
    private function tokenizeWhitespace(string $record): array
    {
        $record = trim($record);
        if ('' === $record) {
            return [];
        }

        /** @var list<string> $tokens */
        $tokens = preg_split('/\s+/', $record) ?: [];

        return array_values(array_filter($tokens, static fn (string $t): bool => '' !== $t));
    }

    /**
     * @return list<string>
     */
    private function tokenizeLines(string $record): array
    {
        $record = trim($record);
        if ('' === $record) {
            return [];
        }

        /** @var list<string> $lines */
        $lines = preg_split('/\r\n|\r|\n/', $record) ?: [];
        $cleaned = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' !== $line) {
                $cleaned[] = $line;
            }
        }

        return $cleaned;
    }

    /**
     * Generic "diff two token lists" for SPF + MX.
     *
     * Strategy: walk previous tokens in order, emit each as Unchanged when it
     * also exists in current (consuming a current slot), otherwise Removed.
     * Any current tokens left over become Added in their original order.
     * This is intentionally simple — it's not a Myers diff, but it gives the
     * "tokens kept, tokens dropped, tokens added" picture the user asked for
     * without trying to align identical adjacent neighbours.
     *
     * @param list<string> $previousTokens
     * @param list<string> $currentTokens
     *
     * @return list<DnsRecordDiffSegment>
     */
    private function diffTokensInOrder(array $previousTokens, array $currentTokens, string $separator): array
    {
        // Build a per-value count of current tokens so the previous walk knows
        // when a token is "still there" vs "really gone".
        $currentCounts = [];
        foreach ($currentTokens as $token) {
            $currentCounts[$token] = ($currentCounts[$token] ?? 0) + 1;
        }

        $segments = [];
        $first = true;

        foreach ($previousTokens as $token) {
            $segments[] = $this->separatorSegment($first, $separator);
            if (($currentCounts[$token] ?? 0) > 0) {
                $segments[] = new DnsRecordDiffSegment($token, DnsRecordDiffKind::Unchanged);
                --$currentCounts[$token];
            } else {
                $segments[] = new DnsRecordDiffSegment($token, DnsRecordDiffKind::Removed);
            }
            $first = false;
        }

        // Now emit the leftover Added tokens — preserve their original order in
        // current so the rendering reads "old (with strikes) then new additions".
        $previousCounts = [];
        foreach ($previousTokens as $token) {
            $previousCounts[$token] = ($previousCounts[$token] ?? 0) + 1;
        }

        foreach ($currentTokens as $token) {
            if (($previousCounts[$token] ?? 0) > 0) {
                --$previousCounts[$token];

                continue;
            }
            $segments[] = $this->separatorSegment($first, $separator);
            $segments[] = new DnsRecordDiffSegment($token, DnsRecordDiffKind::Added);
            $first = false;
        }

        return $this->compact($segments);
    }

    private function separatorSegment(bool $first, string $separator): DnsRecordDiffSegment
    {
        return new DnsRecordDiffSegment(
            $first ? '' : $separator,
            DnsRecordDiffKind::Unchanged,
        );
    }

    /**
     * Convenience for DMARC: the canonical separator between tags is `; `.
     */
    private function joinSeparator(bool $first): DnsRecordDiffSegment
    {
        return $this->separatorSegment($first, '; ');
    }

    /**
     * Drop empty-text segments and merge adjacent same-kind segments so the
     * template renders fewer DOM nodes.
     *
     * @param list<DnsRecordDiffSegment> $segments
     *
     * @return list<DnsRecordDiffSegment>
     */
    private function compact(array $segments): array
    {
        /** @var list<DnsRecordDiffSegment> $compact */
        $compact = [];
        foreach ($segments as $segment) {
            if ('' === $segment->text) {
                continue;
            }

            $lastIndex = count($compact) - 1;
            if ($lastIndex >= 0 && $compact[$lastIndex]->kind === $segment->kind) {
                $compact[$lastIndex] = new DnsRecordDiffSegment(
                    $compact[$lastIndex]->text.$segment->text,
                    $segment->kind,
                );

                continue;
            }

            $compact[] = $segment;
        }

        return array_values($compact);
    }
}
