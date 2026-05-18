<?php

declare(strict_types=1);

namespace App\Value\Dns;

final readonly class DkimCheckResult
{
    /**
     * @param array<DnsIssue> $issues
     * @param array<string>   $recommendations
     * @param list<string>    $detectedProviders providers detected from MX/SPF (empty when user supplied a selector or none detected)
     * @param list<string>    $matchedProviders  providers that publish the selector this result was for (best-effort label)
     */
    public function __construct(
        public ?string $rawRecord,
        public bool $keyExists,
        public ?string $keyType,
        public ?int $keyBits,
        public string $selector,
        public array $issues,
        public array $recommendations,
        public DkimLookupOutcome $outcome = DkimLookupOutcome::NoRecord,
        public ?string $cnameTarget = null,
        public array $detectedProviders = [],
        public array $matchedProviders = [],
    ) {
    }

    public function isPassing(): bool
    {
        return $this->keyExists && null !== $this->keyBits && $this->keyBits >= 2048;
    }
}
