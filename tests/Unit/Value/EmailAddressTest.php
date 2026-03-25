<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\EmailAddress;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testValidEmail(): void
    {
        $email = new EmailAddress('user@example.com');

        self::assertSame('user@example.com', $email->value);
    }

    public function testEmailIsNormalized(): void
    {
        $email = new EmailAddress('  User@Example.COM  ');

        self::assertSame('user@example.com', $email->value);
    }

    public function testToString(): void
    {
        $email = new EmailAddress('user@example.com');

        self::assertSame('user@example.com', $email->toString());
    }

    public function testInvalidEmailThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email address: "not-an-email"');

        new EmailAddress('not-an-email');
    }

    public function testEmptyStringThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmailAddress('');
    }

    public function testMissingAtSignThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmailAddress('userexample.com');
    }

    public function testMissingDomainThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmailAddress('user@');
    }
}
