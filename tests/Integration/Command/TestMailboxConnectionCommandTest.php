<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Services\Mail\FakeMailClient;
use App\Tests\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class TestMailboxConnectionCommandTest extends IntegrationTestCase
{
    public function testSuccessfulConnection(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:mailbox:test');
        $tester = new CommandTester($command);

        $tester->execute([
            'host' => 'imap.test.com',
            'port' => '993',
            'username' => 'user@test.com',
            'password' => 'pass',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Connection successful', $tester->getDisplay());
    }

    public function testFailedConnection(): void
    {
        self::bootKernel();
        $fakeClient = self::getContainer()->get(FakeMailClient::class);
        assert($fakeClient instanceof FakeMailClient);
        $fakeClient->simulateFailure('Connection refused');

        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:mailbox:test');
        $tester = new CommandTester($command);

        $tester->execute([
            'host' => 'bad-host.com',
            'port' => '993',
            'username' => 'user@test.com',
            'password' => 'pass',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Connection failed', $tester->getDisplay());

        $fakeClient->reset();
    }
}
