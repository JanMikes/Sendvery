<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\DowngradeTeamPlan;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class DowngradeTeamPlanTest extends TestCase
{
    public function testConstructor(): void
    {
        $teamId = Uuid::uuid7();
        $message = new DowngradeTeamPlan(teamId: $teamId);

        self::assertSame($teamId, $message->teamId);
    }
}
