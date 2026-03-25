<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Tests\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PollMailboxesCommandTest extends IntegrationTestCase
{
    public function testRunsWithNoActiveConnections(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:mailbox:poll');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No active mailbox connections', $tester->getDisplay());
    }

    public function testDispatchesPollForActiveConnections(): void
    {
        $em = $this->getService(\Doctrine\ORM\EntityManagerInterface::class);

        $team = new \App\Entity\Team(
            id: \Ramsey\Uuid\Uuid::uuid7(),
            name: 'CMD Poll Test',
            slug: 'cmd-poll-test-' . \Ramsey\Uuid\Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $connection = new \App\Entity\MailboxConnection(
            id: \Ramsey\Uuid\Uuid::uuid7(),
            team: $team,
            type: \App\Value\MailboxType::ImapUser,
            host: 'imap.cmd-test.com',
            port: 993,
            encryptedUsername: 'enc',
            encryptedPassword: 'enc',
            encryption: \App\Value\MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($connection);
        $em->flush();
        $em->clear();

        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:mailbox:poll');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Dispatched poll for', $tester->getDisplay());
    }

    public function testPollsSpecificConnection(): void
    {
        $em = $this->getService(\Doctrine\ORM\EntityManagerInterface::class);

        $team = new \App\Entity\Team(
            id: \Ramsey\Uuid\Uuid::uuid7(),
            name: 'CMD Specific',
            slug: 'cmd-specific-' . \Ramsey\Uuid\Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $connection = new \App\Entity\MailboxConnection(
            id: \Ramsey\Uuid\Uuid::uuid7(),
            team: $team,
            type: \App\Value\MailboxType::ImapUser,
            host: 'imap.specific-test.com',
            port: 993,
            encryptedUsername: 'enc',
            encryptedPassword: 'enc',
            encryption: \App\Value\MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($connection);
        $em->flush();
        $em->clear();

        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:mailbox:poll');
        $tester = new CommandTester($command);

        $tester->execute(['--connection' => $connection->id->toString()]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString($connection->id->toString(), $tester->getDisplay());
    }
}
