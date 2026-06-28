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

    public function testPublishPolicyRecordCreatesWhenAbsent(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => []])), // find: none
            new MockResponse($this->json(['success' => true, 'result' => ['id' => 'policy-1']])), // POST
        ]);

        $client = $this->createClient($httpClient);

        self::assertSame('policy-1', $client->publishPolicyRecord('acme.org', 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test'));
    }

    public function testPublishPolicyRecordIsNoOpWhenContentMatches(): void
    {
        $content = 'v=DMARC1; p=quarantine; rua=mailto:reports@sendvery.test; adkim=r; aspf=r; fo=1';
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => [
                ['id' => 'policy-7', 'name' => 'acme.org._dmarc.sendvery.test', 'content' => $content, 'comment' => ''],
            ]])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertSame('policy-7', $client->publishPolicyRecord('acme.org', $content));
    }

    public function testPublishPolicyRecordPatchesWhenContentDrifts(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => [
                ['id' => 'policy-9', 'name' => 'acme.org._dmarc.sendvery.test', 'content' => 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test', 'comment' => ''],
            ]])),
            new MockResponse($this->json(['success' => true, 'result' => ['id' => 'policy-9']])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertSame('policy-9', $client->publishPolicyRecord('acme.org', 'v=DMARC1; p=quarantine; rua=mailto:reports@sendvery.test'));
    }

    public function testPublishPolicyRecordReturnsNullWhenNotConfigured(): void
    {
        $client = $this->createClient(new MockHttpClient(), apiToken: '');

        self::assertNull($client->publishPolicyRecord('acme.org', 'v=DMARC1; p=none'));
    }

    public function testPublishPolicyRecordReturnsNullWhenPostFails(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => []])),
            new MockResponse($this->json(['success' => false, 'errors' => [['code' => 1004, 'message' => 'bad']]])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertNull($client->publishPolicyRecord('acme.org', 'v=DMARC1; p=none'));
    }

    public function testPublishPolicyRecordReturnsNullWhenPatchFails(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => [
                ['id' => 'policy-3', 'name' => 'acme.org._dmarc.sendvery.test', 'content' => 'v=DMARC1; p=none', 'comment' => ''],
            ]])),
            new MockResponse($this->json(['success' => false, 'errors' => [['code' => 1004, 'message' => 'bad']]])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertNull($client->publishPolicyRecord('acme.org', 'v=DMARC1; p=reject'));
    }

    public function testPublishPolicyRecordRecoversFromConcurrentDuplicate(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => []])), // find: none
            new MockResponse($this->json(['success' => false, 'errors' => [['code' => 81057, 'message' => 'exists']]])), // POST dup
            new MockResponse($this->json(['success' => true, 'result' => [
                ['id' => 'policy-dup', 'name' => 'acme.org._dmarc.sendvery.test', 'content' => 'v=DMARC1; p=none', 'comment' => ''],
            ]])), // re-find
        ]);

        $client = $this->createClient($httpClient);

        self::assertSame('policy-dup', $client->publishPolicyRecord('acme.org', 'v=DMARC1; p=none'));
    }

    public function testRemovePolicyRecordDeletesExisting(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => [
                ['id' => 'policy-5', 'name' => 'acme.org._dmarc.sendvery.test', 'content' => 'v=DMARC1; p=none', 'comment' => ''],
            ]])),
            new MockResponse($this->json(['success' => true])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertTrue($client->removePolicyRecord('acme.org'));
    }

    public function testRemovePolicyRecordReturnsTrueWhenAbsent(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => []])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertTrue($client->removePolicyRecord('acme.org'));
    }

    public function testRemovePolicyRecordReturnsFalseWhenDeleteFails(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => [
                ['id' => 'policy-5', 'name' => 'acme.org._dmarc.sendvery.test', 'content' => 'v=DMARC1; p=none', 'comment' => ''],
            ]])),
            new MockResponse($this->json(['success' => false, 'errors' => [['code' => 1004, 'message' => 'nope']]])),
        ]);

        $client = $this->createClient($httpClient);

        self::assertFalse($client->removePolicyRecord('acme.org'));
    }

    public function testRemovePolicyRecordReturnsFalseWhenNotConfigured(): void
    {
        $client = $this->createClient(new MockHttpClient(), zoneId: '');

        self::assertFalse($client->removePolicyRecord('acme.org'));
    }

    public function testPolicyRecordExistsReflectsPresence(): void
    {
        $present = $this->createClient(new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => [
                ['id' => 'policy-1', 'name' => 'acme.org._dmarc.sendvery.test', 'content' => 'v=DMARC1; p=none', 'comment' => ''],
            ]])),
        ]));
        self::assertTrue($present->policyRecordExists('acme.org'));

        $absent = $this->createClient(new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => []])),
        ]));
        self::assertFalse($absent->policyRecordExists('acme.org'));

        self::assertFalse($this->createClient(new MockHttpClient(), apiToken: '')->policyRecordExists('acme.org'));
    }

    public function testFindPolicyRecordReturnsTheRecord(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => [
                ['id' => 'policy-1', 'name' => 'acme.org._dmarc.sendvery.test', 'content' => 'v=DMARC1; p=reject', 'comment' => ''],
            ]])),
        ]);

        $record = $this->createClient($httpClient)->findPolicyRecord('acme.org');

        self::assertNotNull($record);
        self::assertSame('v=DMARC1; p=reject', $record->content);
        self::assertNull($this->createClient(new MockHttpClient(), apiToken: '')->findPolicyRecord('acme.org'));
    }

    public function testListPolicyRecordsExcludesAuthorizationRecords(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse($this->json(['success' => true, 'result' => [
                ['id' => 'policy-1', 'name' => 'acme.org._dmarc.sendvery.test', 'content' => 'v=DMARC1; p=none', 'comment' => ''],
                ['id' => 'auth-1', 'name' => 'acme.org._report._dmarc.sendvery.test', 'content' => 'v=DMARC1;', 'comment' => ''],
            ], 'result_info' => ['total_pages' => 1]])),
        ]);

        $records = $this->createClient($httpClient)->listPolicyRecords();

        self::assertCount(1, $records);
        self::assertSame('acme.org._dmarc.sendvery.test', $records[0]->name);
    }

    public function testExtractPolicyCustomerDomain(): void
    {
        $client = $this->createClient(new MockHttpClient());

        $policy = new CloudflareDnsRecord('p', 'acme.org._dmarc.sendvery.test', 'v=DMARC1; p=none', '', '');
        self::assertSame('acme.org', $client->extractPolicyCustomerDomain($policy));

        // The authorization record collides on the ._dmarc.<reportDomain> suffix — must be rejected.
        $auth = new CloudflareDnsRecord('a', 'acme.org._report._dmarc.sendvery.test', 'v=DMARC1;', '', '');
        self::assertNull($client->extractPolicyCustomerDomain($auth));

        $unrelated = new CloudflareDnsRecord('u', 'unrelated.example.com', 'x', '', '');
        self::assertNull($client->extractPolicyCustomerDomain($unrelated));
    }

    public function testPolicyMethodsGuardAgainstAMalformedReportAddress(): void
    {
        $client = $this->createClientWithReportAddress('no-at-sign');

        // buildPolicyRecordName throws when the report domain can't be derived.
        try {
            $client->publishPolicyRecord('acme.org', 'v=DMARC1; p=none');
            self::fail('Expected a RuntimeException for a malformed report address.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SENDVERY_REPORT_ADDRESS', $e->getMessage());
        }

        // The list/extract helpers degrade gracefully to empty/null.
        self::assertSame([], $client->listPolicyRecords());
        $record = new CloudflareDnsRecord('p', 'acme.org._dmarc.sendvery.test', 'v=DMARC1; p=none', '', '');
        self::assertNull($client->extractPolicyCustomerDomain($record));
    }

    public function testListPolicyRecordsBreaksOnATransportFailure(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]);

        self::assertSame([], $this->createClient($httpClient)->listPolicyRecords());
    }

    public function testAuthorizationMethodsGuardWhenNotConfigured(): void
    {
        $client = $this->createClient(new MockHttpClient(), apiToken: '');

        self::assertFalse($client->removeAuthorizationRecord('acme.org'));
        self::assertFalse($client->authorizationRecordExists('acme.org'));
    }

    public function testAuthorizationHelpersGuardAgainstAMalformedReportAddress(): void
    {
        $client = $this->createClientWithReportAddress('no-at-sign');

        self::assertSame([], $client->listAuthorizationRecords());

        $record = new CloudflareDnsRecord('a', 'acme.org._report._dmarc.sendvery.test', 'v=DMARC1;', '', '');
        self::assertNull($client->extractCustomerDomain($record));

        $this->expectException(\RuntimeException::class);
        $client->publishAuthorizationRecord('acme.org');
    }

    public function testFindTxtRecordAndDeleteFailGracefullyOnTransportError(): void
    {
        $client = $this->createClient(new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
            new MockResponse('', ['error' => 'Connection refused']),
        ]));

        self::assertNull($client->findTxtRecord('acme.org._dmarc.sendvery.test'));
        self::assertFalse($client->deleteRecordById('some-id'));
    }

    public function testListAuthorizationRecordsBreaksOnATransportFailure(): void
    {
        $client = $this->createClient(new MockHttpClient([
            new MockResponse('', ['error' => 'Connection refused']),
        ]));

        self::assertSame([], $client->listAuthorizationRecords());
    }

    private function createClientWithReportAddress(string $reportAddress): CloudflareDnsClient
    {
        return new CloudflareDnsClient(
            httpClient: new MockHttpClient(),
            reportAddressProvider: new ReportAddressProvider($reportAddress),
            logger: new NullLogger(),
            apiToken: self::API_TOKEN,
            zoneId: self::ZONE_ID,
        );
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
