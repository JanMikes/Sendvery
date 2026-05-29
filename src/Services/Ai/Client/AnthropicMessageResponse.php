<?php

declare(strict_types=1);

namespace App\Services\Ai\Client;

/**
 * The parsed result of a forced single-tool Messages API call: the tool's
 * validated `input` payload plus token usage (including cache hit/miss counts
 * for cost observability).
 */
final readonly class AnthropicMessageResponse
{
    /**
     * @param array<string, mixed> $toolInput the validated `input` object of the forced tool_use block
     */
    public function __construct(
        public string $toolName,
        public array $toolInput,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadInputTokens,
        public int $cacheCreationInputTokens,
    ) {
    }
}
