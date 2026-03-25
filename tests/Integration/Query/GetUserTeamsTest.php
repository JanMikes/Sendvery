<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Query\GetUserTeams;
use App\Tests\IntegrationTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetUserTeamsTest extends IntegrationTestCase
{
    public function testReturnsUserTeams(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();

        $userId = Uuid::uuid7();
        $user = new User(id: $userId, email: 'query-'.$userId->toString().'@test.com', createdAt: $now);
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(id: $teamId, name: 'Query Team', slug: 'query-team-'.$teamId->toString(), createdAt: $now);
        $em->persist($team);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: $now,
        );
        $em->persist($membership);
        $em->flush();

        $query = $this->getService(GetUserTeams::class);
        $results = $query->forUser($userId->toString());

        self::assertCount(1, $results);
        self::assertSame($teamId->toString(), $results[0]->teamId);
        self::assertSame('Query Team', $results[0]->teamName);
        self::assertSame('owner', $results[0]->role);
        self::assertSame(1, $results[0]->memberCount);
    }

    public function testReturnsEmptyArrayWhenNoTeams(): void
    {
        $query = $this->getService(GetUserTeams::class);
        $results = $query->forUser(Uuid::uuid7()->toString());

        self::assertSame([], $results);
    }
}
