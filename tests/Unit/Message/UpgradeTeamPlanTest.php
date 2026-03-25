<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\UpgradeTeamPlan;
use App\Value\SubscriptionPlan;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class UpgradeTeamPlanTest extends TestCase
{
    public function testConstructor(): void
    {
        $teamId = Uuid::uuid7();
        $message = new UpgradeTeamPlan(
            teamId: $teamId,
            plan: SubscriptionPlan::Personal,
            stripeSubscriptionId: 'sub_123',
            stripeCustomerId: 'cus_456',
        );

        self::assertSame($teamId, $message->teamId);
        self::assertSame(SubscriptionPlan::Personal, $message->plan);
        self::assertSame('sub_123', $message->stripeSubscriptionId);
        self::assertSame('cus_456', $message->stripeCustomerId);
    }
}
