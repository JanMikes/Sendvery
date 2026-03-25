<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\DmarcPolicy;
use PHPUnit\Framework\TestCase;

final class DmarcPolicyTest extends TestCase
{
    public function testAllCases(): void
    {
        self::assertSame('none', DmarcPolicy::None->value);
        self::assertSame('quarantine', DmarcPolicy::Quarantine->value);
        self::assertSame('reject', DmarcPolicy::Reject->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(DmarcPolicy::Reject, DmarcPolicy::from('reject'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(DmarcPolicy::tryFrom('invalid'));
    }
}
