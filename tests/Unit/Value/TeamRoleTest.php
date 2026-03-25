<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\TeamRole;
use PHPUnit\Framework\TestCase;

final class TeamRoleTest extends TestCase
{
    public function testAllRolesExist(): void
    {
        self::assertSame('owner', TeamRole::Owner->value);
        self::assertSame('admin', TeamRole::Admin->value);
        self::assertSame('member', TeamRole::Member->value);
        self::assertSame('viewer', TeamRole::Viewer->value);
    }

    public function testFromString(): void
    {
        self::assertSame(TeamRole::Owner, TeamRole::from('owner'));
        self::assertSame(TeamRole::Admin, TeamRole::from('admin'));
        self::assertSame(TeamRole::Member, TeamRole::from('member'));
        self::assertSame(TeamRole::Viewer, TeamRole::from('viewer'));
    }

    public function testCaseCount(): void
    {
        self::assertCount(4, TeamRole::cases());
    }
}
