<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\CloudflareDnsClient;
use App\Services\Dns\CloudflareDnsRecord;
use App\Services\ReportAddressProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CloudflareDnsClientTest extends TestCase
{
    private const ZONE_ID = 'test-zone-123';
    private const API_TOKEN = 'test-token-abc';

    public function testPublishCreatesRecordAndReturnsId(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json([
                'success' => true,
                'result' => ['id' => 'cf-record-42'],
            ])),
        ]);

        $client = $this->createClient($httpClient);
        $recordId = $client->publishAuthorizationRecord('example.com');

        self::assertSame('cf-record-42', $recordId);
    }

    public function testPublishHandlesDuplicateByFetchingExistingRecord(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json([
                'success' => false,
                'errors' => [['code' => 81057, 'message' => 'Record already exists']],
            ])),
            new MockResponse($this->json([
                'success' => true,
                'result' => [
                    ['id' => 'existing-record-99', 'name' => 'example.com._report._dmarc.sendvery.test', 'content' => 'v=DMARC1;', 'comment' => ''],
                ],
            ])),
        ]);

        $client = $this->createClient($httpClient);
        $recordId = $client->publishAuthorizationRecord('example.com');

        self::assertSame('existing-record-99', $recordId);
    }

    public function testPublishReturnsNullOnApiFailure(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json([
                'success' => false,
                'errors' => [['code' => 9109, 'message' => 'Invalid token']],
            ])),
        ]);

        $client = $this->createClient($httpClient);
        $recordId = $client->publishAuthorizationRecord('example.com');

        self::assertNull($recordId);
    }

    public function testPublishReturnsNullWhenNotConfigured(): void
    {
        $client = $this->createClient(new MockHttpClient(), apiToken: '', zoneId: '');
        $recordId = $client->publishAuthorizationRecord('example.com');

        self::assertNull($recordId);
    }

    public function testRemoveDeletesExistingRecord(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json([
                'success' => true,
                'result' => [
                    ['id' => 'record-to-delete', 'name' => 'example.com._report._dmarc.sendvery.test', 'content' => 'v=DMARC1;', 'comment' => ''],
                ],
            ])),
            new MockResponse($this->json([
                'success' => true,
                'result' => ['id' => 'record-to-delete'],
            ])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertTrue($client->removeAuthorizationRecord('example.com'));
    }

    public function testRemoveReturnsTrueWhenRecordNotFound(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json([
                'success' => true,
                'result' => [],
            ])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertTrue($client->removeAuthorizationRecord('example.com'));
    }

    public function testDeleteByIdHandlesNotFoundAsSuccess(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json([
                'success' => false,
                'errors' => [['code' => 81044, 'message' => 'Record not found']],
            ])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertTrue($client->deleteRecordById('nonexistent-id'));
    }

    public function testAuthorizationRecordExistsReturnsTrueWhenFound(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json([
                'success' => true,
                'result' => [
                    ['id' => 'found-record', 'name' => 'example.com._report._dmarc.sendvery.test', 'content' => 'v=DMARC1;', 'comment' => ''],
                ],
            ])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertTrue($client->authorizationRecordExists('example.com'));
    }

    public function testAuthorizationRecordExistsReturnsFalseWhenMissing(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json([
                'success' => true,
                'result' => [],
            ])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertFalse($client->authorizationRecordExists('example.com'));
    }

    public function testListAuthorizationRecordsHandlesPagination(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json([
                'success' => true,
                'result' => [
                    ['id' => 'rec-1', 'name' => 'a.example._report._dmarc.sendvery.test', 'content' => 'v=DMARC1;', 'comment' => ''],
                ],
                'result_info' => ['total_pages' => 2],
            ])),
            new MockResponse($this->json([
                'success' => true,
                'result' => [
                    ['id' => 'rec-2', 'name' => 'b.example._report._dmarc.sendvery.test', 'content' => 'v=DMARC1;', 'comment' => ''],
                ],
                'result_info' => ['total_pages' => 2],
            ])),
        ]);

        $client = $this->createClient($httpClient);
        $records = $client->listAuthorizationRecords();

        self::assertCount(2, $records);
        self::assertSame('rec-1', $records[0]->id);
        self::assertSame('rec-2', $records[1]->id);
    }

    public function testExtractCustomerDomainFromRecord(): void
    {
        $client = $this->createClient(new MockHttpClient());

        $record = new CloudflareDnsRecord(
            id: 'rec-1',
            name: 'example.com._report._dmarc.sendvery.test',
            content: 'v=DMARC1;',
            comment: '',
            createdOn: '2026-01-01T00:00:00Z',
        );

        self::assertSame('example.com', $client->extractCustomerDomain($record));
    }

    public function testExtractCustomerDomainReturnsNullForUnrelatedRecord(): void
    {
        $client = $this->createClient(new MockHttpClient());

        $record = new CloudflareDnsRecord(
            id: 'rec-1',
            name: 'unrelated.example.com',
            content: 'v=DMARC1;',
            comment: '',
            createdOn: '2026-01-01T00:00:00Z',
        );

        self::assertNull($client->extractCustomerDomain($record));
    }

    public function testPublishSendsCorrectRecordNameUsingReportDomain(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody): MockResponse {
            if ('POST' === $method) {
                $capturedBody = json_decode($options['body'] ?? '{}', true, flags: \JSON_THROW_ON_ERROR);
            }

            return new MockResponse($this->json([
                'success' => true,
                'result' => ['id' => 'cf-123'],
            ]));
        });

        $client = $this->createClient($httpClient);
        $client->publishAuthorizationRecord('acme.org');

        self::assertIsArray($capturedBody);
        self::assertSame('acme.org._report._dmarc.sendvery.test', $capturedBody['name']);
    }

    public function testPublishHandlesTransportException(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $client = $this->createClient($httpClient);
        $recordId = $client->publishAuthorizationRecord('example.com');

        self::assertNull($recordId);
    }

    public function testIsConfiguredReturnsFalseWhenTokenEmpty(): void
    {
        $client = $this->createClient(new MockHttpClient(), apiToken: '');

        self::assertFalse($client->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenZoneIdEmpty(): void
    {
        $client = $this->createClient(new MockHttpClient(), zoneId: '');

        self::assertFalse($client->isConfigured());
    }

    public function testIsConfiguredReturnsTrueWhenBothSet(): void
    {
        $client = $this->createClient(new MockHttpClient());

        self::assertTrue($client->isConfigured());
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR);
    }

    private function createClient(
        MockHttpClient $httpClient,
        string $apiToken = self::API_TOKEN,
        string $zoneId = self::ZONE_ID,
    ): CloudflareDnsClient {
        return new CloudflareDnsClient(
            httpClient: $httpClient,
            reportAddressProvider: new ReportAddressProvider('reports@sendvery.test'),
            logger: new NullLogger(),
            apiToken: $apiToken,
            zoneId: $zoneId,
        );
    }
}
