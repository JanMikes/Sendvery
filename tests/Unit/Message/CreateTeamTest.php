<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\CreateTeam;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class CreateTeamTest extends TestCase
{
    public function testProperties(): void
    {
        $teamId = Uuid::uuid7();
        $ownerUserId = Uuid::uuid7();

        $command = new CreateTeam(
            teamId: $teamId,
            name: 'Acme Corp',
            ownerUserId: $ownerUserId,
        );

        self::assertSame($teamId, $command->teamId);
        self::assertSame('Acme Corp', $command->name);
        self::assertSame($ownerUserId, $command->ownerUserId);
    }
}
