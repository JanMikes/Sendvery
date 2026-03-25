<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\MailboxEncryption;
use PHPUnit\Framework\TestCase;

final class MailboxEncryptionTest extends TestCase
{
    public function testAllCases(): void
    {
        self::assertSame('ssl', MailboxEncryption::Ssl->value);
        self::assertSame('tls', MailboxEncryption::Tls->value);
        self::assertSame('starttls', MailboxEncryption::StartTls->value);
        self::assertSame('none', MailboxEncryption::None->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(MailboxEncryption::Ssl, MailboxEncryption::from('ssl'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(MailboxEncryption::tryFrom('invalid'));
    }
}
