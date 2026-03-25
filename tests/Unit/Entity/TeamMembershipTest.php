<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Value\TeamRole;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class TeamMembershipTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $id = Uuid::uuid7();
        $now = new \DateTimeImmutable('2026-03-25 10:00:00');

        $user = new User(
            id: Uuid::uuid7(),
            email: 'user@example.com',
            createdAt: $now,
        );

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Test Team',
            slug: 'test-team',
            createdAt: $now,
        );

        $membership = new TeamMembership(
            id: $id,
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: $now,
        );

        self::assertSame($id, $membership->id);
        self::assertSame($user, $membership->user);
        self::assertSame($team, $membership->team);
        self::assertSame(TeamRole::Owner, $membership->role);
        self::assertSame($now, $membership->joinedAt);
    }
}
