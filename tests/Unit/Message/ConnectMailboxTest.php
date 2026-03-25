<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\ConnectMailbox;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ConnectMailboxTest extends TestCase
{
    public function testProperties(): void
    {
        $connectionId = Uuid::uuid7();
        $teamId = Uuid::uuid7();
        $domainId = Uuid::uuid7();

        $message = new ConnectMailbox(
            connectionId: $connectionId,
            teamId: $teamId,
            domainId: $domainId,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            username: 'user@example.com',
            password: 'secret',
            encryption: MailboxEncryption::Ssl,
        );

        self::assertSame($connectionId, $message->connectionId);
        self::assertSame($teamId, $message->teamId);
        self::assertSame($domainId, $message->domainId);
        self::assertSame(MailboxType::ImapUser, $message->type);
        self::assertSame('imap.example.com', $message->host);
        self::assertSame(993, $message->port);
        self::assertSame('user@example.com', $message->username);
        self::assertSame('secret', $message->password);
        self::assertSame(MailboxEncryption::Ssl, $message->encryption);
    }

    public function testNullDomainId(): void
    {
        $message = new ConnectMailbox(
            connectionId: Uuid::uuid7(),
            teamId: Uuid::uuid7(),
            domainId: null,
            type: MailboxType::ImapHosted,
            host: 'imap.example.com',
            port: 993,
            username: 'user@example.com',
            password: 'secret',
            encryption: MailboxEncryption::Ssl,
        );

        self::assertNull($message->domainId);
    }
}
