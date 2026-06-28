<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Dns;

use App\Services\Dns\CloudflareDnsClient;
use App\Services\ReportAddressProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Client contract test for the single-record invariant (plan R1). The Fake
 * publisher cannot reproduce Cloudflare's "multiple TXT at one name" footgun, so
 * this test is the only guard that changed content is UPDATED in place
 * (GET -> PATCH on the existing id) and never POSTed as a second record. Mocks
 * HttpClientInterface — never a real Cloudflare call.
 */
final class CloudflareDnsClientPolicyRecordTest extends TestCase
{
    #[Test]
    public function changedContentIssuesAPatchAndLeavesExactlyOneTxt(): void
    {
        $findResponse = new MockResponse((string) json_encode([
            'success' => true,
            'result' => [
                [
                    'id' => 'existing-policy-id',
                    'name' => 'acme.org._dmarc.sendvery.test',
                    'content' => 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test',
                    'comment' => '',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $patchResponse = new MockResponse((string) json_encode([
            'success' => true,
            'result' => ['id' => 'existing-policy-id'],
        ], \JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient([$findResponse, $patchResponse]);

        $client = new CloudflareDnsClient(
            httpClient: $httpClient,
            reportAddressProvider: new ReportAddressProvider('reports@sendvery.test'),
            logger: new NullLogger(),
            apiToken: 'test-token',
            zoneId: 'test-zone',
        );

        $recordId = $client->publishPolicyRecord('acme.org', 'v=DMARC1; p=quarantine; rua=mailto:reports@sendvery.test');

        self::assertSame('existing-policy-id', $recordId);
        // Exactly two requests: GET to find, PATCH to update — never a second POST.
        self::assertSame('GET', $findResponse->getRequestMethod());
        self::assertSame('PATCH', $patchResponse->getRequestMethod());
        self::assertStringContainsString('/dns_records/existing-policy-id', $patchResponse->getRequestUrl());
    }
}
