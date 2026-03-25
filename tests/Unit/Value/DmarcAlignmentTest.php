<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\DmarcAlignment;
use PHPUnit\Framework\TestCase;

final class DmarcAlignmentTest extends TestCase
{
    public function testAllCases(): void
    {
        self::assertSame('r', DmarcAlignment::Relaxed->value);
        self::assertSame('s', DmarcAlignment::Strict->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(DmarcAlignment::Strict, DmarcAlignment::from('s'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(DmarcAlignment::tryFrom('x'));
    }
}
