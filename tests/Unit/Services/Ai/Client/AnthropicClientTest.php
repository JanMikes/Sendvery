<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Ai\Client;

use App\Exceptions\AnthropicRequestFailed;
use App\Services\Ai\Client\AnthropicClient;
use App\Value\AiModel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AnthropicClientTest extends TestCase
{
    private const string TOOL_NAME = 'emit_report_explanation';

    #[Test]
    public function itForcesTheToolAndCachesTheSystemPrefixThenReturnsValidatedInput(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse((string) json_encode([
                'content' => [
                    ['type' => 'tool_use', 'name' => self::TOOL_NAME, 'input' => ['explanation' => 'All good.']],
                ],
                'stop_reason' => 'tool_use',
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 40,
                    'cache_read_input_tokens' => 96,
                    'cache_creation_input_tokens' => 0,
                ],
            ]), ['http_code' => 200]);
        });

        $client = new AnthropicClient($http, new NullLogger(), 'sk-test', 'https://api.anthropic.test');

        $result = $client->requestStructuredOutput(
            AiModel::Haiku,
            'SYSTEM PROMPT TEXT',
            ['name' => self::TOOL_NAME, 'description' => 'Emit it', 'input_schema' => ['type' => 'object']],
            '<report_facts>{"a":1}</report_facts>',
            AiModel::Haiku->maxOutputTokens(),
        );

        self::assertSame('All good.', $result->toolInput['explanation']);
        self::assertSame(96, $result->cacheReadInputTokens);
        self::assertSame(40, $result->outputTokens);
        self::assertSame(self::TOOL_NAME, $result->toolName);

        self::assertNotNull($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.anthropic.test/v1/messages', $captured['url']);

        $body = json_decode((string) $captured['options']['body'], true);
        self::assertSame('claude-haiku-4-5', $body['model']);
        self::assertSame(700, $body['max_tokens']);
        // Output shape is locked to our tool — the structural injection defense.
        self::assertSame(['type' => 'tool', 'name' => self::TOOL_NAME], $body['tool_choice']);
        self::assertTrue($body['tools'][0]['strict']);
        // Exactly one cache breakpoint, on the system block (the cacheable prefix).
        self::assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        self::assertSame('SYSTEM PROMPT TEXT', $body['system'][0]['text']);
        // Untrusted facts ride in the user turn, after the cached prefix.
        self::assertSame('<report_facts>{"a":1}</report_facts>', $body['messages'][0]['content']);

        $headers = implode("\n", $captured['options']['headers']);
        self::assertStringContainsString('anthropic-version: 2023-06-01', $headers);
        self::assertStringContainsString('x-api-key: sk-test', $headers);
    }

    #[Test]
    public function transientServerStatusesAreRetryable(): void
    {
        foreach ([429, 500, 529] as $status) {
            $client = $this->clientReturning($status);

            try {
                $this->fire($client);
                self::fail(sprintf('Expected failure for HTTP %d', $status));
            } catch (AnthropicRequestFailed $e) {
                self::assertTrue($e->retryable, sprintf('HTTP %d must be retryable', $status));
            }
        }
    }

    #[Test]
    public function clientErrorsAreNotRetryable(): void
    {
        foreach ([400, 401, 403, 404] as $status) {
            $client = $this->clientReturning($status);

            try {
                $this->fire($client);
                self::fail(sprintf('Expected failure for HTTP %d', $status));
            } catch (AnthropicRequestFailed $e) {
                self::assertFalse($e->retryable, sprintf('HTTP %d must not be retryable', $status));
            }
        }
    }

    #[Test]
    public function transportFailuresAreTreatedAsRetryable(): void
    {
        // A MockResponse carrying an `error` raises a TransportException when the
        // client reads it — the network-failure path.
        $http = new MockHttpClient([new MockResponse('', ['error' => 'Connection refused'])]);
        $client = new AnthropicClient($http, new NullLogger(), 'sk-test', 'https://api.anthropic.test');

        try {
            $this->fire($client);
            self::fail('Expected a transport failure');
        } catch (AnthropicRequestFailed $e) {
            self::assertTrue($e->retryable);
        }
    }

    #[Test]
    public function aResponseWithoutAToolUseBlockIsAPermanentFailure(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse((string) json_encode([
            'content' => [['type' => 'text', 'text' => 'I refuse to use the tool.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
        ]), ['http_code' => 200]));

        $client = new AnthropicClient($http, new NullLogger(), 'sk-test', 'https://api.anthropic.test');

        try {
            $this->fire($client);
            self::fail('Expected failure when tool_use block is missing');
        } catch (AnthropicRequestFailed $e) {
            self::assertFalse($e->retryable);
        }
    }

    private function clientReturning(int $status): AnthropicClient
    {
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse(
            (string) json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'nope']]),
            ['http_code' => $status],
        ));

        return new AnthropicClient($http, new NullLogger(), 'sk-test', 'https://api.anthropic.test');
    }

    private function fire(AnthropicClient $client): void
    {
        $client->requestStructuredOutput(
            AiModel::Sonnet,
            'SYSTEM',
            ['name' => self::TOOL_NAME, 'description' => 'd', 'input_schema' => ['type' => 'object']],
            'user',
            500,
        );
    }
}
