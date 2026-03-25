<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\MailboxType;
use PHPUnit\Framework\TestCase;

final class MailboxTypeTest extends TestCase
{
    public function testAllCases(): void
    {
        self::assertSame('imap_user', MailboxType::ImapUser->value);
        self::assertSame('imap_hosted', MailboxType::ImapHosted->value);
        self::assertSame('pop3_user', MailboxType::Pop3User->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(MailboxType::ImapUser, MailboxType::from('imap_user'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(MailboxType::tryFrom('invalid'));
    }
}
