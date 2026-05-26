<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Tests\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncAuthorizationRecordsCommandTest extends IntegrationTestCase
{
    public function testSkipsSyncWhenCloudflareNotConfigured(): void
    {
        self::bootKernel();
        assert(null !== self::$kernel);
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dns:sync-authorization-records');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('not configured', $tester->getDisplay());
    }
}
