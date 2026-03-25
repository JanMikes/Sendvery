<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\Disposition;
use PHPUnit\Framework\TestCase;

final class DispositionTest extends TestCase
{
    public function testAllCases(): void
    {
        self::assertSame('none', Disposition::None->value);
        self::assertSame('quarantine', Disposition::Quarantine->value);
        self::assertSame('reject', Disposition::Reject->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(Disposition::Quarantine, Disposition::from('quarantine'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(Disposition::tryFrom('invalid'));
    }
}
