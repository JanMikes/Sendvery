<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DkimDetectionResult
{
    /**
     * @param list<string> $detectedProviders
     * @param list<string> $matchedProviders
     */
    public function __construct(
        public string $selector,
        public bool $keyFound,
        public ?string $keyType,
        public ?int $keyBits,
        public array $detectedProviders,
        public array $matchedProviders,
        public string $checkedAt,
    ) {
    }

    /**
     * @param array{selector: string|null, key_found: bool|string, key_type: string|null, key_bits: int|string|null, detected_providers: string|null, matched_providers: string|null, checked_at: string} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            selector: $row['selector'] ?? 'default',
            keyFound: (bool) $row['key_found'],
            keyType: $row['key_type'],
            keyBits: null !== $row['key_bits'] ? (int) $row['key_bits'] : null,
            detectedProviders: self::decodeJsonList($row['detected_providers'] ?? null),
            matchedProviders: self::decodeJsonList($row['matched_providers'] ?? null),
            checkedAt: $row['checked_at'],
        );
    }

    /** @return list<string> */
    private static function decodeJsonList(?string $json): array
    {
        if (null === $json || '' === $json) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
