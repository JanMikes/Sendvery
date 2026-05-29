<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Ai;

use App\Services\Ai\AiInsightsService;
use App\Services\Ai\AnthropicAiInsightsService;
use App\Services\Ai\CachingAiInsightsService;
use App\Services\Ai\Client\AnthropicClient;
use App\Tests\IntegrationTestCase;
use App\Tests\TestSupport\AnthropicMockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AnthropicWiringTest extends IntegrationTestCase
{
    public function testInterfaceResolvesToTheCachingThenGatedThenAnthropicChain(): void
    {
        $service = $this->getService(AiInsightsService::class);

        self::assertInstanceOf(CachingAiInsightsService::class, $service);
        self::assertInstanceOf(AnthropicAiInsightsService::class, $this->getService(AnthropicAiInsightsService::class));
    }

    /**
     * The load-bearing guarantee: the container-wired Anthropic client must use a
     * MockHttpClient, so no test can make a real Anthropic request even though
     * .env.test sets an API key. If the test wiring ever regresses to a real
     * transport, this fails.
     */
    public function testWiredAnthropicClientUsesAMockHttpClient(): void
    {
        $client = $this->getService(AnthropicClient::class);

        $property = new \ReflectionProperty(AnthropicClient::class, 'httpClient');
        $httpClient = $property->getValue($client);

        self::assertInstanceOf(HttpClientInterface::class, $httpClient);
        self::assertInstanceOf(AnthropicMockHttpClient::class, $httpClient);
    }
}
