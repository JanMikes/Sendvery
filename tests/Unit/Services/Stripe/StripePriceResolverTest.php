<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Stripe;

use App\Services\Stripe\StripePriceResolver;
use App\Value\SubscriptionPlan;
use PHPUnit\Framework\TestCase;

final class StripePriceResolverTest extends TestCase
{
    public function testResolvesPriceFromMap(): void
    {
        $resolver = new StripePriceResolver([
            'personal' => 'price_personal_123',
            'team' => 'price_team_456',
        ]);

        self::assertSame('price_personal_123', $resolver->getPriceId(SubscriptionPlan::Personal));
        self::assertSame('price_team_456', $resolver->getPriceId(SubscriptionPlan::Team));
    }

    public function testFreePlanThrowsException(): void
    {
        $resolver = new StripePriceResolver();

        $this->expectException(\LogicException::class);
        $resolver->getPriceId(SubscriptionPlan::Free);
    }
}
