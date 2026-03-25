<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\MailboxConnectionCreated;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class MailboxConnectionTest extends TestCase
{
    private Team $team;

    protected function setUp(): void
    {
        $this->team = new Team(
            id: Uuid::uuid7(),
            name: 'Test Team',
            slug: 'test-team',
            createdAt: new \DateTimeImmutable(),
        );
        $this->team->popEvents();
    }

    public function testConstructorSetsFields(): void
    {
        $id = Uuid::uuid7();
        $createdAt = new \DateTimeImmutable('2026-03-25 10:00:00');

        $connection = new MailboxConnection(
            id: $id,
            team: $this->team,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            encryptedUsername: 'enc-user',
            encryptedPassword: 'enc-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: $createdAt,
        );

        self::assertSame($id, $connection->id);
        self::assertSame($this->team, $connection->team);
        self::assertNull($connection->monitoredDomain);
        self::assertSame(MailboxType::ImapUser, $connection->type);
        self::assertSame('imap.example.com', $connection->host);
        self::assertSame(993, $connection->port);
        self::assertSame('enc-user', $connection->encryptedUsername);
        self::assertSame('enc-pass', $connection->encryptedPassword);
        self::assertSame(MailboxEncryption::Ssl, $connection->encryption);
        self::assertNull($connection->lastPolledAt);
        self::assertNull($connection->lastError);
        self::assertTrue($connection->isActive);
        self::assertSame($createdAt, $connection->createdAt);
    }

    public function testConstructorWithOptionalFields(): void
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $this->team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable(),
        );

        $lastPolled = new \DateTimeImmutable('2026-03-24');

        $connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $this->team,
            type: MailboxType::ImapHosted,
            host: 'imap.example.com',
            port: 993,
            encryptedUsername: 'enc-user',
            encryptedPassword: 'enc-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            monitoredDomain: $domain,
            isActive: false,
            lastPolledAt: $lastPolled,
            lastError: 'Some error',
        );

        self::assertSame($domain, $connection->monitoredDomain);
        self::assertFalse($connection->isActive);
        self::assertSame($lastPolled, $connection->lastPolledAt);
        self::assertSame('Some error', $connection->lastError);
    }

    public function testRecordsMailboxConnectionCreatedEvent(): void
    {
        $id = Uuid::uuid7();

        $connection = new MailboxConnection(
            id: $id,
            team: $this->team,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            encryptedUsername: 'enc-user',
            encryptedPassword: 'enc-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );

        $events = $connection->popEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(MailboxConnectionCreated::class, $events[0]);
        self::assertSame($id, $events[0]->connectionId);
        self::assertSame($this->team->id, $events[0]->teamId);
    }

    public function testMarkPolled(): void
    {
        $connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $this->team,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            encryptedUsername: 'enc-user',
            encryptedPassword: 'enc-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            lastError: 'old error',
        );

        $polledAt = new \DateTimeImmutable('2026-03-25 12:00:00');
        $connection->markPolled($polledAt);

        self::assertSame($polledAt, $connection->lastPolledAt);
        self::assertNull($connection->lastError);
    }

    public function testMarkError(): void
    {
        $connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $this->team,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            encryptedUsername: 'enc-user',
            encryptedPassword: 'enc-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );

        $connection->markError('Connection timeout');

        self::assertSame('Connection timeout', $connection->lastError);
    }
}
