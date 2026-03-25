<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\SubscriptionPlan;
use PHPUnit\Framework\TestCase;

final class SubscriptionPlanTest extends TestCase
{
    public function testAllCases(): void
    {
        self::assertSame('free', SubscriptionPlan::Free->value);
        self::assertSame('personal', SubscriptionPlan::Personal->value);
        self::assertSame('team', SubscriptionPlan::Team->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(SubscriptionPlan::Personal, SubscriptionPlan::from('personal'));
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(SubscriptionPlan::tryFrom('enterprise'));
    }
}
