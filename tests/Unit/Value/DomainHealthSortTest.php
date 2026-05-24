<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\DomainHealthSort;
use PHPUnit\Framework\TestCase;

final class DomainHealthSortTest extends TestCase
{
    public function testAllCasesCarryExpectedValues(): void
    {
        self::assertSame('worst', DomainHealthSort::Worst->value);
        self::assertSame('best', DomainHealthSort::Best->value);
        self::assertSame('most', DomainHealthSort::Most->value);
    }

    public function testTryFromWorstReturnsWorstCase(): void
    {
        self::assertSame(DomainHealthSort::Worst, DomainHealthSort::tryFrom('worst'));
    }

    public function testTryFromBestReturnsBestCase(): void
    {
        self::assertSame(DomainHealthSort::Best, DomainHealthSort::tryFrom('best'));
    }

    public function testTryFromMostReturnsMostCase(): void
    {
        self::assertSame(DomainHealthSort::Most, DomainHealthSort::tryFrom('most'));
    }

    public function testTryFromUnknownValueReturnsNull(): void
    {
        self::assertNull(DomainHealthSort::tryFrom('garbage'));
    }
}
