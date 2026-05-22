<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\BillingInterval;
use PHPUnit\Framework\TestCase;

final class BillingIntervalTest extends TestCase
{
    public function testAllCases(): void
    {
        self::assertSame('monthly', BillingInterval::Monthly->value);
        self::assertSame('annual', BillingInterval::Annual->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(BillingInterval::Monthly, BillingInterval::from('monthly'));
        self::assertSame(BillingInterval::Annual, BillingInterval::from('annual'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(BillingInterval::tryFrom('quarterly'));
    }

    public function testStripeInterval(): void
    {
        self::assertSame('month', BillingInterval::Monthly->stripeInterval());
        self::assertSame('year', BillingInterval::Annual->stripeInterval());
    }
}
