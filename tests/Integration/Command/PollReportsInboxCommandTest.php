<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Services\Reports\FakeCentralInboxClient;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\CentralInboxFolder;
use App\Value\Reports\FetchedEnvelope;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PollReportsInboxCommandTest extends IntegrationTestCase
{
    public function testAsyncModePrintsDispatchedMessage(): void
    {
        $client = $this->getService(FakeCentralInboxClient::class);
        $client->reset();

        $tester = $this->commandTester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Dispatched central inbox poll.', $tester->getDisplay());
    }

    public function testSyncModeRunsIngestionInline(): void
    {
        $client = $this->getService(FakeCentralInboxClient::class);
        $client->reset();
        $client->addEnvelope(new FetchedEnvelope(
            messageId: '<cli-1@google.com>',
            fromAddress: 'dmarc@google.com',
            subject: 'Report',
            receivedAt: new \DateTimeImmutable(),
            rawEml: 'body',
            uid: 5,
            uidvalidity: 1,
        ));

        $tester = $this->commandTester();
        $exit = $tester->execute(['--sync' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Ingested 1 new envelope(s).', $tester->getDisplay());
        self::assertSame(
            [5 => CentralInboxFolder::Pending],
            $client->getMovedUids(),
        );
    }

    private function commandTester(): CommandTester
    {
        // Reuse the kernel that getService() already booted so the command runs
        // against the same FakeCentralInboxClient instance we seeded above.
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('sendvery:reports:poll-inbox');

        return new CommandTester($command);
    }
}
