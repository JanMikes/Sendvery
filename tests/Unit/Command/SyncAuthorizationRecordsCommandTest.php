<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SyncAuthorizationRecordsCommand;
use App\Services\Dns\CloudflareDnsClient;
use App\Services\ReportAddressProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SyncAuthorizationRecordsCommandTest extends TestCase
{
    public function testCreatesRecordsForDomainsWithoutCloudflareEntry(): void
    {
        $httpClient = new MockHttpClient([
            $this->jsonResponse(['success' => true, 'result' => [], 'result_info' => ['total_pages' => 1]]),
            $this->jsonResponse(['success' => true, 'result' => ['id' => 'new-cf-1']]),
        ]);

        $connection = $this->createMock(Connection::class);
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['id' => 'dom-1', 'domain' => 'example.com', 'cloudflare_auth_record_id' => null],
        ]);
        $connection->method('executeQuery')->willReturn($result);
        $connection->expects(self::once())->method('executeStatement');

        $client = $this->createCfClient($httpClient);
        $tester = $this->runCommand($client, $connection);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 created', $tester->getDisplay());
    }

    public function testDeletesStaleRecordsForRemovedDomains(): void
    {
        $httpClient = new MockHttpClient([
            $this->jsonResponse([
                'success' => true,
                'result' => [
                    ['id' => 'stale-1', 'name' => 'gone.example._report._dmarc.sendvery.test', 'content' => 'v=DMARC1;', 'comment' => ''],
                ],
                'result_info' => ['total_pages' => 1],
            ]),
            $this->jsonResponse(['success' => true, 'result' => ['id' => 'stale-1']]),
        ]);

        $connection = $this->createStub(Connection::class);
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $connection->method('executeQuery')->willReturn($result);

        $client = $this->createCfClient($httpClient);
        $tester = $this->runCommand($client, $connection);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 deleted', $tester->getDisplay());
    }

    public function testReconcilesRecordIdOnEntityWhenCloudflareHasIt(): void
    {
        $httpClient = new MockHttpClient([
            $this->jsonResponse([
                'success' => true,
                'result' => [
                    ['id' => 'cf-existing', 'name' => 'example.com._report._dmarc.sendvery.test', 'content' => 'v=DMARC1;', 'comment' => ''],
                ],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $connection = $this->createMock(Connection::class);
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['id' => 'dom-1', 'domain' => 'example.com', 'cloudflare_auth_record_id' => null],
        ]);
        $connection->method('executeQuery')->willReturn($result);
        $connection->expects(self::once())->method('executeStatement');

        $client = $this->createCfClient($httpClient);
        $tester = $this->runCommand($client, $connection);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 reconciled', $tester->getDisplay());
    }

    public function testSkipsReconciliationWhenEntityAlreadyHasCorrectId(): void
    {
        $httpClient = new MockHttpClient([
            $this->jsonResponse([
                'success' => true,
                'result' => [
                    ['id' => 'cf-ok', 'name' => 'example.com._report._dmarc.sendvery.test', 'content' => 'v=DMARC1;', 'comment' => ''],
                ],
                'result_info' => ['total_pages' => 1],
            ]),
        ]);

        $connection = $this->createMock(Connection::class);
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['id' => 'dom-1', 'domain' => 'example.com', 'cloudflare_auth_record_id' => 'cf-ok'],
        ]);
        $connection->method('executeQuery')->willReturn($result);
        $connection->expects(self::never())->method('executeStatement');

        $client = $this->createCfClient($httpClient);
        $tester = $this->runCommand($client, $connection);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('0 created, 0 deleted, 0 reconciled', $tester->getDisplay());
    }

    /** @param array<string, mixed> $data */
    private function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    private function createCfClient(MockHttpClient $httpClient): CloudflareDnsClient
    {
        return new CloudflareDnsClient(
            httpClient: $httpClient,
            reportAddressProvider: new ReportAddressProvider('reports@sendvery.test'),
            logger: new NullLogger(),
            apiToken: 'test-token',
            zoneId: 'test-zone',
        );
    }

    private function runCommand(CloudflareDnsClient $client, Connection $connection): CommandTester
    {
        $command = new SyncAuthorizationRecordsCommand($client, $connection);
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
