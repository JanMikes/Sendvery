<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Services\TeamContext;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class TeamContextTest extends TestCase
{
    public function testInitiallyNull(): void
    {
        $context = new TeamContext();

        self::assertNull($context->getCurrentTeamId());
    }

    public function testSetAndGetCurrentTeamId(): void
    {
        $context = new TeamContext();
        $teamId = Uuid::uuid7();

        $context->setCurrentTeamId($teamId);

        self::assertSame($teamId, $context->getCurrentTeamId());
    }
}
