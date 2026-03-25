<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\ConnectMailbox;
use App\MessageHandler\ConnectMailboxHandler;
use App\Services\CredentialEncryptor;
use App\Tests\IntegrationTestCase;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class ConnectMailboxHandlerTest extends IntegrationTestCase
{
    public function testCreatesConnectionWithEncryptedCredentials(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(ConnectMailboxHandler::class);
        $encryptor = $this->getService(CredentialEncryptor::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();
        $em->clear();

        $connectionId = Uuid::uuid7();
        $handler(new ConnectMailbox(
            connectionId: $connectionId,
            teamId: $team->id,
            domainId: null,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            username: 'user@example.com',
            password: 'secret-password',
            encryption: MailboxEncryption::Ssl,
        ));
        $em->flush();
        $em->clear();

        $connection = $em->find(MailboxConnection::class, $connectionId);
        self::assertNotNull($connection);
        self::assertSame('imap.example.com', $connection->host);
        self::assertSame(993, $connection->port);
        self::assertTrue($connection->isActive);

        // Credentials must be encrypted, not plaintext
        self::assertNotSame('user@example.com', $connection->encryptedUsername);
        self::assertNotSame('secret-password', $connection->encryptedPassword);

        // But decryptable
        self::assertSame('user@example.com', $encryptor->decrypt($connection->encryptedUsername));
        self::assertSame('secret-password', $encryptor->decrypt($connection->encryptedPassword));
    }

    public function testCreatesConnectionWithDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(ConnectMailboxHandler::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test-domain-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();
        $em->clear();

        $connectionId = Uuid::uuid7();
        $handler(new ConnectMailbox(
            connectionId: $connectionId,
            teamId: $team->id,
            domainId: $domainId,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            username: 'user@example.com',
            password: 'pass',
            encryption: MailboxEncryption::Ssl,
        ));
        $em->flush();
        $em->clear();

        $connection = $em->find(MailboxConnection::class, $connectionId);
        self::assertNotNull($connection);
        self::assertNotNull($connection->monitoredDomain);
        self::assertSame($domainId->toString(), $connection->monitoredDomain->id->toString());
    }

    public function testSetsLastErrorOnConnectionFailure(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(ConnectMailboxHandler::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test-fail-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();
        $em->clear();

        // FakeMailClient is used in test env — simulate failure
        $fakeClient = $this->getService(\App\Services\Mail\FakeMailClient::class);
        $fakeClient->simulateFailure('Auth failed');

        $connectionId = Uuid::uuid7();
        $handler(new ConnectMailbox(
            connectionId: $connectionId,
            teamId: $team->id,
            domainId: null,
            type: MailboxType::ImapUser,
            host: 'bad-host.example.com',
            port: 993,
            username: 'user',
            password: 'pass',
            encryption: MailboxEncryption::Ssl,
        ));
        $em->flush();
        $em->clear();

        $connection = $em->find(MailboxConnection::class, $connectionId);
        self::assertNotNull($connection);
        self::assertSame('Auth failed', $connection->lastError);

        $fakeClient->reset();
    }
}
