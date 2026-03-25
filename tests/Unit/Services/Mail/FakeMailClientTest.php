<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Mail;

use App\Entity\MailboxConnection;
use App\Entity\Team;
use App\Services\Mail\FakeMailClient;
use App\Value\MailAttachment;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\MailMessage;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class FakeMailClientTest extends TestCase
{
    private FakeMailClient $client;
    private MailboxConnection $connection;

    protected function setUp(): void
    {
        $this->client = new FakeMailClient();

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test',
            slug: 'test',
            createdAt: new \DateTimeImmutable(),
        );

        $this->connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.test.com',
            port: 993,
            encryptedUsername: 'enc',
            encryptedPassword: 'enc',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testFetchReturnsAddedMessages(): void
    {
        $message = new MailMessage(
            messageId: '<msg-1>',
            subject: 'DMARC Report',
            from: 'dmarc@google.com',
            date: new \DateTimeImmutable(),
            attachments: [new MailAttachment('report.xml', '<feedback/>', 'text/xml')],
        );

        $this->client->addMessage($message);

        $results = iterator_to_array($this->client->fetchDmarcReports($this->connection));

        self::assertCount(1, $results);
        self::assertSame($message, $results[0]);
    }

    public function testFetchReturnsEmptyByDefault(): void
    {
        $results = iterator_to_array($this->client->fetchDmarcReports($this->connection));

        self::assertCount(0, $results);
    }

    public function testSimulateFailure(): void
    {
        $this->client->simulateFailure('Auth failed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Auth failed');
        iterator_to_array($this->client->fetchDmarcReports($this->connection));
    }

    public function testTestConnectionSuccess(): void
    {
        $this->client->addMessage(new MailMessage(
            messageId: '<msg-1>',
            subject: 'Test',
            from: 'test@test.com',
            date: new \DateTimeImmutable(),
            attachments: [],
        ));

        $result = $this->client->testConnection($this->connection);

        self::assertTrue($result->success);
        self::assertNull($result->error);
        self::assertSame(1, $result->mailboxCount);
    }

    public function testTestConnectionFailure(): void
    {
        $this->client->simulateFailure('Connection refused');

        $result = $this->client->testConnection($this->connection);

        self::assertFalse($result->success);
        self::assertSame('Connection refused', $result->error);
        self::assertSame(0, $result->mailboxCount);
    }

    public function testMarkAsProcessedTracksMessageIds(): void
    {
        $message = new MailMessage(
            messageId: '<msg-42>',
            subject: 'Report',
            from: 'test@test.com',
            date: new \DateTimeImmutable(),
            attachments: [],
        );

        $this->client->markAsProcessed($this->connection, $message);

        self::assertSame(['<msg-42>'], $this->client->getProcessedMessageIds());
    }

    public function testReset(): void
    {
        $this->client->addMessage(new MailMessage(
            messageId: '<msg-1>',
            subject: 'Test',
            from: 'test@test.com',
            date: new \DateTimeImmutable(),
            attachments: [],
        ));
        $this->client->simulateFailure('fail');
        $this->client->reset();

        $result = $this->client->testConnection($this->connection);
        self::assertTrue($result->success);
        self::assertSame(0, $result->mailboxCount);
        self::assertSame([], $this->client->getProcessedMessageIds());
    }
}
