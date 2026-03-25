<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Repository\TeamMembershipRepository;
use App\Tests\IntegrationTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class TeamMembershipRepositoryTest extends IntegrationTestCase
{
    private function createUserAndTeam(EntityManagerInterface $em): array
    {
        $userId = Uuid::uuid7();
        $teamId = Uuid::uuid7();
        $now = new \DateTimeImmutable();

        $user = new User(id: $userId, email: 'member-' . $userId->toString() . '@test.com', createdAt: $now);
        $team = new Team(id: $teamId, name: 'Team ' . $teamId->toString(), slug: 'team-' . $teamId->toString(), createdAt: $now);

        $em->persist($user);
        $em->persist($team);

        return [$user, $team];
    }

    public function testFindForUserReturnsMemberships(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        [$user, $team] = $this->createUserAndTeam($em);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        );
        $em->persist($membership);
        $em->flush();

        $repository = $this->getService(TeamMembershipRepository::class);
        $memberships = $repository->findForUser($user->id);

        self::assertCount(1, $memberships);
        self::assertSame($user->id->toString(), $memberships[0]->user->id->toString());
    }

    public function testFindForTeamReturnsMemberships(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        [$user, $team] = $this->createUserAndTeam($em);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Member,
            joinedAt: new \DateTimeImmutable(),
        );
        $em->persist($membership);
        $em->flush();

        $repository = $this->getService(TeamMembershipRepository::class);
        $memberships = $repository->findForTeam($team->id);

        self::assertCount(1, $memberships);
        self::assertSame($team->id->toString(), $memberships[0]->team->id->toString());
    }

    public function testFindMembershipReturnsMatch(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        [$user, $team] = $this->createUserAndTeam($em);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Admin,
            joinedAt: new \DateTimeImmutable(),
        );
        $em->persist($membership);
        $em->flush();

        $repository = $this->getService(TeamMembershipRepository::class);
        $found = $repository->findMembership($user->id, $team->id);

        self::assertNotNull($found);
        self::assertSame(TeamRole::Admin, $found->role);
    }

    public function testFindMembershipReturnsNullWhenNotFound(): void
    {
        $repository = $this->getService(TeamMembershipRepository::class);

        self::assertNull($repository->findMembership(Uuid::uuid7(), Uuid::uuid7()));
    }

    public function testFindForUserReturnsEmptyWhenNoMemberships(): void
    {
        $repository = $this->getService(TeamMembershipRepository::class);

        self::assertSame([], $repository->findForUser(Uuid::uuid7()));
    }

    public function testFindForTeamReturnsEmptyWhenNoMemberships(): void
    {
        $repository = $this->getService(TeamMembershipRepository::class);

        self::assertSame([], $repository->findForTeam(Uuid::uuid7()));
    }
}
