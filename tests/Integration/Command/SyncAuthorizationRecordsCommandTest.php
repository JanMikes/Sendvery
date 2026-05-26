<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Services\Dns\CloudflareDnsClient;
use App\Tests\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncAuthorizationRecordsCommandTest extends IntegrationTestCase
{
    public function testRunsSuccessfullyWithNoActiveDomains(): void
    {
        self::bootKernel();
        assert(null !== self::$kernel);
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dns:sync-authorization-records');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Sync complete', $tester->getDisplay());
    }

    public function testCloudflareClientIsConfiguredInTestEnvironment(): void
    {
        $client = $this->getService(CloudflareDnsClient::class);

        self::assertTrue($client->isConfigured(), 'The Cloudflare client must be configured in the test environment so the SaaS mode UI can be verified.');
    }
}
