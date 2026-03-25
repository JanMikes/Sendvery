<?php

declare(strict_types=1);

namespace App\Tests\Integration\Doctrine;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\IntegrationTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class TeamFilterTest extends IntegrationTestCase
{
    public function testFilterScopesQueriesByTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();

        $user = new User(id: Uuid::uuid7(), email: 'filter-'.Uuid::uuid7()->toString().'@test.com', createdAt: $now);
        $em->persist($user);

        $team1Id = Uuid::uuid7();
        $team1 = new Team(id: $team1Id, name: 'Team 1', slug: 'team1-'.$team1Id->toString(), createdAt: $now);
        $em->persist($team1);

        $team2Id = Uuid::uuid7();
        $team2 = new Team(id: $team2Id, name: 'Team 2', slug: 'team2-'.$team2Id->toString(), createdAt: $now);
        $em->persist($team2);

        $m1 = new TeamMembership(id: Uuid::uuid7(), user: $user, team: $team1, role: TeamRole::Owner, joinedAt: $now);
        $m2 = new TeamMembership(id: Uuid::uuid7(), user: $user, team: $team2, role: TeamRole::Member, joinedAt: $now);
        $em->persist($m1);
        $em->persist($m2);
        $em->flush();

        // Enable the team filter with team1
        $filter = $em->getFilters()->getFilter('team_filter');
        $filter->setParameter('team_id', $team1Id->toString());

        $memberships = $em->getRepository(TeamMembership::class)->findAll();

        // Only team1's membership should be returned
        self::assertCount(1, $memberships);
        self::assertSame($team1Id->toString(), $memberships[0]->team->id->toString());
    }
}
