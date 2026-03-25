<?php

declare(strict_types=1);

namespace App\Value\Dns;

readonly final class DkimCheckResult
{
    /**
     * @param array<DnsIssue> $issues
     * @param array<string> $recommendations
     */
    public function __construct(
        public ?string $rawRecord,
        public bool $keyExists,
        public ?string $keyType,
        public ?int $keyBits,
        public string $selector,
        public array $issues,
        public array $recommendations,
    ) {
    }

    public function isPassing(): bool
    {
        return $this->keyExists && $this->keyBits !== null && $this->keyBits >= 2048;
    }
}
