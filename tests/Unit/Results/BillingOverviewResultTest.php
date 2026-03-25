<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\BillingOverviewResult;
use App\Value\SubscriptionPlan;
use PHPUnit\Framework\TestCase;

final class BillingOverviewResultTest extends TestCase
{
    public function testFromDatabaseRow(): void
    {
        $result = BillingOverviewResult::fromDatabaseRow([
            'plan' => 'personal',
            'stripe_customer_id' => 'cus_123',
            'stripe_subscription_id' => 'sub_456',
            'plan_warning_at' => null,
            'domain_count' => '3',
            'member_count' => '1',
        ]);

        self::assertSame(SubscriptionPlan::Personal, $result->plan);
        self::assertSame('cus_123', $result->stripeCustomerId);
        self::assertSame('sub_456', $result->stripeSubscriptionId);
        self::assertNull($result->planWarningAt);
        self::assertSame(3, $result->domainCount);
        self::assertSame(1, $result->memberCount);
    }

    public function testFromDatabaseRowWithWarning(): void
    {
        $result = BillingOverviewResult::fromDatabaseRow([
            'plan' => 'free',
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
            'plan_warning_at' => '2026-03-20 10:00:00',
            'domain_count' => 0,
            'member_count' => 0,
        ]);

        self::assertSame(SubscriptionPlan::Free, $result->plan);
        self::assertNull($result->stripeCustomerId);
        self::assertNotNull($result->planWarningAt);
        self::assertSame(0, $result->domainCount);
    }
}
