<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\MailboxConnectionErrorCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MailboxConnectionErrorCodeTest extends TestCase
{
    #[Test]
    public function hasSixCases(): void
    {
        self::assertCount(6, MailboxConnectionErrorCode::cases());
    }

    #[Test]
    public function authenticationFailedHumanMessageMentionsCredentials(): void
    {
        $msg = MailboxConnectionErrorCode::AuthenticationFailed->humanMessage();
        self::assertNotEmpty($msg);
        self::assertStringContainsString('Authentication', $msg);
    }

    #[Test]
    public function connectionRefusedHumanMessageMentionsHost(): void
    {
        $msg = MailboxConnectionErrorCode::ConnectionRefused->humanMessage();
        self::assertNotEmpty($msg);
        self::assertStringContainsString('refused', $msg);
    }

    #[Test]
    public function connectionTimeoutHumanMessageMentionsTimeout(): void
    {
        $msg = MailboxConnectionErrorCode::ConnectionTimeout->humanMessage();
        self::assertNotEmpty($msg);
        self::assertStringContainsString('timed out', $msg);
    }

    #[Test]
    public function starttlsNotSupportedHumanMessageSuggestsAlternative(): void
    {
        $msg = MailboxConnectionErrorCode::StarttlsNotSupported->humanMessage();
        self::assertNotEmpty($msg);
        self::assertStringContainsString('STARTTLS', $msg);
    }

    #[Test]
    public function inboxNotFoundHumanMessageMentionsInbox(): void
    {
        $msg = MailboxConnectionErrorCode::InboxNotFound->humanMessage();
        self::assertNotEmpty($msg);
        self::assertStringContainsString('INBOX', $msg);
    }

    #[Test]
    public function unknownHumanMessageIsNonEmpty(): void
    {
        $msg = MailboxConnectionErrorCode::Unknown->humanMessage();
        self::assertNotEmpty($msg);
    }
}
