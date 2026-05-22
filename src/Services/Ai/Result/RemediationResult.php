<?php

declare(strict_types=1);

namespace App\Services\Ai\Result;

final readonly class RemediationResult
{
    /**
     * @param list<SuggestedDnsRecord> $suggestedDnsRecords
     */
    public function __construct(
        public string $instructionsMarkdown,
        public array $suggestedDnsRecords,
    ) {
    }
}
