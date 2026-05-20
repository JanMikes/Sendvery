<?php

declare(strict_types=1);

namespace App\Value\Dns;

final readonly class DmarcRuaInstruction
{
    public function __construct(
        public ?string $currentRecord,
        public string $finalRecord,
        public bool $alreadyConfigured,
    ) {
    }

    public static function build(?string $currentRecord, string $reportAddress): self
    {
        $currentTrimmed = null !== $currentRecord ? trim($currentRecord) : null;

        if (null === $currentTrimmed || '' === $currentTrimmed || !str_starts_with($currentTrimmed, 'v=DMARC1')) {
            $finalRecord = sprintf('v=DMARC1; p=none; rua=mailto:%s; fo=1; adkim=r; aspf=r', $reportAddress);

            return new self(
                currentRecord: $currentTrimmed,
                finalRecord: $finalRecord,
                alreadyConfigured: false,
            );
        }

        $tags = self::parseTags($currentTrimmed);
        $ruaRaw = $tags['rua'] ?? '';
        $ruaAddresses = self::parseAddresses($ruaRaw);

        if (in_array(strtolower($reportAddress), array_map('strtolower', $ruaAddresses), true)) {
            return new self(
                currentRecord: $currentTrimmed,
                finalRecord: $currentTrimmed,
                alreadyConfigured: true,
            );
        }

        $ruaAddresses[] = $reportAddress;
        $newRuaValue = implode(',', array_map(static fn (string $addr): string => 'mailto:' . $addr, $ruaAddresses));

        $tags['rua'] = $newRuaValue;

        return new self(
            currentRecord: $currentTrimmed,
            finalRecord: self::rebuildRecord($tags),
            alreadyConfigured: false,
        );
    }

    /** @return array<string, string> */
    private static function parseTags(string $record): array
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
            $tags[trim(substr($part, 0, $eqPos))] = trim(substr($part, $eqPos + 1));
        }

        return $tags;
    }

    /** @return list<string> */
    private static function parseAddresses(string $value): array
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

    /** @param array<string, string> $tags */
    private static function rebuildRecord(array $tags): string
    {
        // Preserve canonical ordering: v, p, rua come first, then the rest.
        $ordered = [];
        foreach (['v', 'p', 'sp', 'rua', 'ruf', 'adkim', 'aspf', 'pct', 'fo', 'rf', 'ri'] as $key) {
            if (array_key_exists($key, $tags)) {
                $ordered[$key] = $tags[$key];
                unset($tags[$key]);
            }
        }

        $ordered = [...$ordered, ...$tags];

        $parts = [];
        foreach ($ordered as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, $value);
        }

        return implode('; ', $parts);
    }
}
