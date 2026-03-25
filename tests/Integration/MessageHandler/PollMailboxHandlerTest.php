<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\PollMailbox;
use App\MessageHandler\PollMailboxHandler;
use App\Services\Mail\FakeMailClient;
use App\Tests\IntegrationTestCase;
use App\Value\MailAttachment;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\MailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class PollMailboxHandlerTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private FakeMailClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->fakeClient = $this->getService(FakeMailClient::class);
        $this->fakeClient->reset();
    }

    public function testSkipsInactiveConnection(): void
    {
        $team = $this->createTeam();
        $connection = $this->createConnection($team, isActive: false);
        $this->em->flush();
        $this->em->clear();

        $handler = $this->getService(PollMailboxHandler::class);
        $handler(new PollMailbox(connectionId: $connection->id));

        // Should not have tried to fetch
        self::assertSame([], $this->fakeClient->getProcessedMessageIds());
    }

    public function testPollsAndProcessesReports(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team);
        $connection = $this->createConnection($team, monitoredDomain: $domain);
        $this->em->flush();
        $this->em->clear();

        $xml = file_get_contents(__DIR__.'/../../Fixtures/minimal-report.xml');
        assert(is_string($xml));

        $this->fakeClient->addMessage(new MailMessage(
            messageId: '<poll-test@example.com>',
            subject: 'DMARC Report',
            from: 'noreply-dmarc-support@google.com',
            date: new \DateTimeImmutable(),
            attachments: [new MailAttachment('report.xml', $xml, 'text/xml')],
        ));

        $handler = $this->getService(PollMailboxHandler::class);
        $handler(new PollMailbox(connectionId: $connection->id));

        self::assertSame(['<poll-test@example.com>'], $this->fakeClient->getProcessedMessageIds());

        // Connection should be marked as polled
        $this->em->clear();
        $refreshed = $this->em->find(MailboxConnection::class, $connection->id);
        self::assertNotNull($refreshed);
        self::assertNotNull($refreshed->lastPolledAt);
        self::assertNull($refreshed->lastError);
    }

    public function testHandlesConnectionFailure(): void
    {
        $team = $this->createTeam();
        $connection = $this->createConnection($team);
        $this->em->flush();
        $this->em->clear();

        $this->fakeClient->simulateFailure('Connection refused');

        $handler = $this->getService(PollMailboxHandler::class);
        $handler(new PollMailbox(connectionId: $connection->id));

        $this->em->clear();
        $refreshed = $this->em->find(MailboxConnection::class, $connection->id);
        self::assertNotNull($refreshed);
        self::assertSame('Connection refused', $refreshed->lastError);
    }

    public function testSkipsAttachmentsWithoutDomain(): void
    {
        $team = $this->createTeam();
        // No monitored domain linked
        $connection = $this->createConnection($team);
        $this->em->flush();
        $this->em->clear();

        $xml = file_get_contents(__DIR__.'/../../Fixtures/minimal-report.xml');
        assert(is_string($xml));

        $this->fakeClient->addMessage(new MailMessage(
            messageId: '<no-domain@test.com>',
            subject: 'DMARC Report',
            from: 'dmarc@google.com',
            date: new \DateTimeImmutable(),
            attachments: [new MailAttachment('report.xml', $xml, 'text/xml')],
        ));

        $handler = $this->getService(PollMailboxHandler::class);
        $handler(new PollMailbox(connectionId: $connection->id));

        // Should still mark as processed, but no reports dispatched
        self::assertSame(['<no-domain@test.com>'], $this->fakeClient->getProcessedMessageIds());
    }

    private function createTeam(): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Poll Test',
            slug: 'poll-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($team);

        return $team;
    }

    private function createDomain(Team $team): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'poll-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($domain);

        return $domain;
    }

    private function createConnection(Team $team, ?MonitoredDomain $monitoredDomain = null, bool $isActive = true): MailboxConnection
    {
        $connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.test.com',
            port: 993,
            encryptedUsername: 'enc-user',
            encryptedPassword: 'enc-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            monitoredDomain: $monitoredDomain,
            isActive: $isActive,
        );
        $this->em->persist($connection);

        return $connection;
    }
}
