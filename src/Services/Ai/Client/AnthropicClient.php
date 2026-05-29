<?php

declare(strict_types=1);

namespace App\Services\Ai\Client;

use App\Exceptions\AnthropicRequestFailed;
use App\Value\AiModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin Anthropic Messages API adapter (no SDK), modeled on
 * {@see \App\Services\Dns\CloudflareDnsClient}: Symfony HttpClient,
 * `#[Autowire(env:)]` credentials, try/catch + logger.
 *
 * Two deliberate choices encode the security + cost posture:
 *  - We FORCE a single tool (`tool_choice: {type:tool}`) so the model's output
 *    shape is fixed by our schema. Injected text in the report data cannot turn
 *    the response into free-form prose or add fields — the structural defense
 *    against prompt injection.
 *  - One `cache_control` breakpoint sits on the (static, per-task) system block.
 *    Render order is tools → system → messages, so this caches the tools+system
 *    prefix; the volatile per-report facts ride in `messages`, after the
 *    breakpoint, and never invalidate the cached prefix.
 *
 * Prompt caching and strict structured tool use are GA — no `anthropic-beta`
 * header. We send no `temperature`/`top_p`/`top_k`, no `effort`, and no
 * `thinking`: narration needs none of them, and each would 400 on at least one
 * of the tiers we run (effort on Haiku, sampling params on Opus).
 */
final readonly class AnthropicClient
{
    private const string ANTHROPIC_VERSION = '2023-06-01';

    /** Anthropic marks exactly these statuses transient; everything else is permanent. */
    private const array RETRYABLE_STATUSES = [429, 500, 529];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire(env: 'ANTHROPIC_API_KEY')]
        private string $apiKey,
        #[Autowire(env: 'ANTHROPIC_API_BASE_URL')]
        private string $baseUrl,
    ) {
    }

    /**
     * Make one forced-tool call and return the validated tool input + usage.
     *
     * @param array{name: string, description: string, input_schema: array<string, mixed>} $tool
     *
     * @throws AnthropicRequestFailed on transport failure, non-2xx, or a response
     *                                that lacks the forced tool_use block
     */
    public function requestStructuredOutput(
        AiModel $model,
        string $systemPrompt,
        array $tool,
        string $userMessage,
        int $maxTokens,
    ): AnthropicMessageResponse {
        $payload = [
            'model' => $model->value,
            'max_tokens' => $maxTokens,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'tools' => [$tool + ['strict' => true]],
            'tool_choice' => ['type' => 'tool', 'name' => $tool['name']],
            'messages' => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl.'/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'content-type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 30,
                'max_duration' => 45,
            ]);

            $statusCode = $response->getStatusCode();
            /** @var array<string, mixed> $data */
            $data = $response->toArray(throw: false);
        } catch (HttpExceptionInterface $e) {
            // Transport or decode failure (DNS, connection, timeout, garbled body)
            // — treat as transient so Messenger retries.
            $this->logger->error('Anthropic request failed at transport: {error}', [
                'error' => $e->getMessage(),
                'model' => $model->value,
            ]);

            throw new AnthropicRequestFailed($e->getMessage(), retryable: true, previous: $e);
        }

        if ($statusCode >= 400) {
            $retryable = in_array($statusCode, self::RETRYABLE_STATUSES, true);
            $this->logger->error('Anthropic returned HTTP {status}', [
                'status' => $statusCode,
                'body' => $data,
                'model' => $model->value,
            ]);

            throw new AnthropicRequestFailed(sprintf('Anthropic API returned HTTP %d.', $statusCode), retryable: $retryable);
        }

        return $this->parseToolUse($data, $model);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseToolUse(array $data, AiModel $model): AnthropicMessageResponse
    {
        $content = $data['content'] ?? null;

        if (is_array($content)) {
            foreach ($content as $block) {
                if (!is_array($block) || 'tool_use' !== ($block['type'] ?? null) || !is_array($block['input'] ?? null)) {
                    continue;
                }

                /** @var array<string, mixed> $usage */
                $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
                $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
                $cacheCreation = (int) ($usage['cache_creation_input_tokens'] ?? 0);

                $this->logger->info('Anthropic structured call completed', [
                    'model' => $model->value,
                    'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                    'cache_read_input_tokens' => $cacheRead,
                    'cache_creation_input_tokens' => $cacheCreation,
                ]);

                return new AnthropicMessageResponse(
                    toolName: is_string($block['name'] ?? null) ? $block['name'] : '',
                    toolInput: $block['input'],
                    inputTokens: (int) ($usage['input_tokens'] ?? 0),
                    outputTokens: (int) ($usage['output_tokens'] ?? 0),
                    cacheReadInputTokens: $cacheRead,
                    cacheCreationInputTokens: $cacheCreation,
                );
            }
        }

        // Forced tool_choice guarantees a tool_use block; its absence is a
        // contract violation, not a transient error — don't retry.
        $this->logger->error('Anthropic response missing tool_use block', ['model' => $model->value]);

        throw new AnthropicRequestFailed('Anthropic response missing tool_use block.', retryable: false);
    }
}
