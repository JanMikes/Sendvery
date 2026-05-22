<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exceptions;

use App\Exceptions\AiNotEnabledForPlan;
use App\Value\SubscriptionPlan;
use PHPUnit\Framework\TestCase;

final class AiNotEnabledForPlanTest extends TestCase
{
    public function testCarriesPlanAndDescribesIt(): void
    {
        $exception = new AiNotEnabledForPlan(SubscriptionPlan::Personal);

        self::assertSame(SubscriptionPlan::Personal, $exception->plan);
        self::assertStringContainsString('personal', $exception->getMessage());
        self::assertStringContainsString('AI Insights', $exception->getMessage());
    }
}
