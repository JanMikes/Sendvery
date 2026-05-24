<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\ParsedDmarcRecord;

/**
 * Pure parser for the published DMARC TXT-record string we already store on
 * {@see \App\Entity\DnsCheckResult::$rawRecord}. Extracts the small subset of
 * tags TASK-100 needs (policy, rua, ruf, pct) into a typed DTO.
 *
 * Stays separate from {@see DmarcChecker} on purpose: that service is the
 * homepage-style "go live to DNS and validate", while this parser only
 * interprets text we already have on hand — it never touches the network.
 * Same tag-splitting + address-parsing rules as DmarcChecker so both stay
 * in lockstep on edge cases (mailto: prefix, multi-address rua=, etc.).
 */
final readonly class DmarcRecordParser
{
    public function parse(?string $rawRecord): ?ParsedDmarcRecord
    {
        if (null === $rawRecord) {
            return null;
        }

        $trimmed = trim($rawRecord);
        if ('' === $trimmed) {
            return null;
        }

        if (!str_starts_with($trimmed, 'v=DMARC1')) {
            return null;
        }

        $tags = $this->parseTags($trimmed);

        return new ParsedDmarcRecord(
            policy: $tags['p'] ?? null,
            ruaAddresses: $this->parseAddresses($tags['rua'] ?? ''),
            rufAddresses: $this->parseAddresses($tags['ruf'] ?? ''),
            pct: isset($tags['pct']) ? (int) $tags['pct'] : null,
        );
    }

    /** @return array<string, string> */
    private function parseTags(string $record): array
    {
        $tags = [];
        foreach (explode(';', $record) as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            $eqPos = strpos($part, '=');
            if (false === $eqPos) {
                continue;
            }
            $key = trim(substr($part, 0, $eqPos));
            $value = trim(substr($part, $eqPos + 1));
            $tags[$key] = $value;
        }

        return $tags;
    }

    /** @return list<string> */
    private function parseAddresses(string $value): array
    {
        if ('' === $value) {
            return [];
        }

        $addresses = [];
        foreach (explode(',', $value) as $addr) {
            $addr = trim($addr);
            if (str_starts_with($addr, 'mailto:')) {
                $addresses[] = substr($addr, 7);
            } elseif ('' !== $addr) {
                $addresses[] = $addr;
            }
        }

        return $addresses;
    }
}
