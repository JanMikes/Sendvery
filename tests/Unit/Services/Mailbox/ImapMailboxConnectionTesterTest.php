<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Mailbox;

use App\Services\Mailbox\ImapMailboxConnectionTester;
use App\Value\MailboxConnectionErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImapMailboxConnectionTesterTest extends TestCase
{
    #[Test]
    #[DataProvider('errorMessageProvider')]
    public function classifyErrorRecognisesMessage(string $message, MailboxConnectionErrorCode $expected): void
    {
        self::assertSame($expected, ImapMailboxConnectionTester::classifyError($message));
    }

    /** @return iterable<string, array{string, MailboxConnectionErrorCode}> */
    public static function errorMessageProvider(): iterable
    {
        // Genuine authentication failures
        yield 'IMAP auth-failed response' => ['[AUTHENTICATIONFAILED] Invalid credentials (Failure)', MailboxConnectionErrorCode::AuthenticationFailed];
        yield 'literal "Authentication failed"' => ['Authentication failed', MailboxConnectionErrorCode::AuthenticationFailed];
        yield 'login failed phrasing' => ['IMAP login failed: server says no', MailboxConnectionErrorCode::AuthenticationFailed];
        yield 'credentials phrasing' => ['Bad credentials supplied', MailboxConnectionErrorCode::AuthenticationFailed];
        yield 'invalid login phrasing' => ['Invalid login or password', MailboxConnectionErrorCode::AuthenticationFailed];

        yield 'connection refused' => ['Connection refused by upstream', MailboxConnectionErrorCode::ConnectionRefused];

        yield 'timed out' => ['Network timed out after 3s', MailboxConnectionErrorCode::ConnectionTimeout];
        yield 'timeout literal' => ['Timeout reached', MailboxConnectionErrorCode::ConnectionTimeout];

        yield 'starttls' => ['STARTTLS not supported by server', MailboxConnectionErrorCode::StarttlsNotSupported];

        yield 'inbox missing' => ['Could not open INBOX folder', MailboxConnectionErrorCode::InboxNotFound];
        yield 'folder missing' => ['No such folder', MailboxConnectionErrorCode::InboxNotFound];

        // Tightened pattern — must NOT classify these as AuthenticationFailed
        yield 'oauth capability (no real auth failure)' => ['OAUTH not supported', MailboxConnectionErrorCode::Unknown];
        yield 'authority namespace (no real auth failure)' => ['Certificate signed by unknown authority', MailboxConnectionErrorCode::Unknown];
        yield 'AUTH= capability listing' => ['Server supports AUTH=PLAIN AUTH=LOGIN', MailboxConnectionErrorCode::Unknown];
        yield 'authored phrasing' => ['Plugin authored by webklex', MailboxConnectionErrorCode::Unknown];

        yield 'completely unknown message' => ['Some random garbage', MailboxConnectionErrorCode::Unknown];
    }
}
