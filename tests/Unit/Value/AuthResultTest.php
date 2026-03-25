<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\AuthResult;
use PHPUnit\Framework\TestCase;

final class AuthResultTest extends TestCase
{
    public function testAllCases(): void
    {
        self::assertSame('pass', AuthResult::Pass->value);
        self::assertSame('fail', AuthResult::Fail->value);
        self::assertSame('softfail', AuthResult::SoftFail->value);
        self::assertSame('neutral', AuthResult::Neutral->value);
        self::assertSame('none', AuthResult::None->value);
        self::assertSame('temperror', AuthResult::TempError->value);
        self::assertSame('permerror', AuthResult::PermError->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(AuthResult::Pass, AuthResult::from('pass'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(AuthResult::tryFrom('invalid'));
    }
}
