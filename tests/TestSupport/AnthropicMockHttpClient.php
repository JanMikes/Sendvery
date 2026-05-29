<?php

declare(strict_types=1);

namespace App\Tests\TestSupport;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The HttpClient injected into {@see \App\Services\Ai\Client\AnthropicClient} in
 * the test environment. Because .env.test sets ANTHROPIC_API_KEY, the real AI
 * chain is wired in tests — this guarantees those calls never reach the network.
 *
 * By default it returns one canned tool-use response whose `input` carries every
 * field any task's mapper reads, so a WebTest that triggers any AI method parses
 * cleanly with no per-test setup. Tests that assert specific behavior can queue
 * their own responses with push().
 */
final class AnthropicMockHttpClient extends MockHttpClient
{
    /** @var list<MockResponse> */
    private array $queue = [];

    public function __construct()
    {
        parent::__construct(function (): MockResponse {
            return array_shift($this->queue) ?? self::defaultToolResponse();
        });
    }

    public function push(MockResponse $response): void
    {
        $this->queue[] = $response;
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function toolResponse(array $input, int $statusCode = 200): MockResponse
    {
        return new MockResponse((string) json_encode([
            'content' => [['type' => 'tool_use', 'name' => 'emit', 'input' => $input]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 20, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ]), ['http_code' => $statusCode]);
    }

    private static function defaultToolResponse(): MockResponse
    {
        return self::toolResponse([
            'explanation' => 'This is a test AI explanation of the report.',
            'severity' => 'warning',
            'recommended_action' => 'Review the failing senders in your dashboard.',
            'summary' => 'A test weekly summary of your email authentication.',
            'key_metrics' => [['label' => 'Messages', 'value' => '100']],
            'recommendations' => ['A test recommendation.'],
            'instructions' => 'Test remediation instructions for the failing record.',
            'label' => 'Test Sender',
            'confidence' => 0.8,
        ]);
    }
}
