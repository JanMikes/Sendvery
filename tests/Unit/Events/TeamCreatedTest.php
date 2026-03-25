<?php

declare(strict_types=1);

namespace App\Tests\Unit\Events;

use App\Events\TeamCreated;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class TeamCreatedTest extends TestCase
{
    public function testProperties(): void
    {
        $teamId = Uuid::uuid7();

        $event = new TeamCreated($teamId);

        self::assertSame($teamId, $event->teamId);
    }
}
