<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\DomainHealthFilter;
use PHPUnit\Framework\TestCase;

final class DomainHealthFilterTest extends TestCase
{
    public function testAllCasesCarryExpectedValues(): void
    {
        self::assertSame('healthy', DomainHealthFilter::Healthy->value);
        self::assertSame('attention', DomainHealthFilter::Attention->value);
        self::assertSame('unverified', DomainHealthFilter::Unverified->value);
    }

    public function testTryFromHealthyReturnsHealthyCase(): void
    {
        self::assertSame(DomainHealthFilter::Healthy, DomainHealthFilter::tryFrom('healthy'));
    }

    public function testTryFromAttentionReturnsAttentionCase(): void
    {
        self::assertSame(DomainHealthFilter::Attention, DomainHealthFilter::tryFrom('attention'));
    }

    public function testTryFromUnverifiedReturnsUnverifiedCase(): void
    {
        self::assertSame(DomainHealthFilter::Unverified, DomainHealthFilter::tryFrom('unverified'));
    }

    public function testTryFromUnknownValueReturnsNull(): void
    {
        self::assertNull(DomainHealthFilter::tryFrom('garbage'));
    }
}
