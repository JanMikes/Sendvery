<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Exceptions\CannotRemoveTeamOwner;
use App\Message\RemoveTeamMember;
use App\MessageHandler\RemoveTeamMemberHandler;
use App\Repository\TeamMembershipRepository;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class RemoveTeamMemberHandlerTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private RemoveTeamMemberHandler $handler;
    private TeamMembershipRepository $membershipRepository;
    private IdentityProvider $identityProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->handler = $this->getService(RemoveTeamMemberHandler::class);
        $this->membershipRepository = $this->getService(TeamMembershipRepository::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
    }

    public function testRemovesNonOwnerMember(): void
    {
        $team = $this->createTeam();
        $member = $this->createMember($team, 'rm@example.com', TeamRole::Member);
        $this->em->flush();

        ($this->handler)(new RemoveTeamMember($member->membershipId));
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->membershipRepository->findMembership($member->userId, $team->id));
    }

    public function testRefusesToRemoveOwner(): void
    {
        $team = $this->createTeam();
        $owner = $this->createMember($team, 'owner-rm@example.com', TeamRole::Owner);
        $this->em->flush();

        $this->expectException(CannotRemoveTeamOwner::class);

        ($this->handler)(new RemoveTeamMember($owner->membershipId));
    }

    private function createTeam(): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Remove Test',
            slug: 'remove-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($team);

        return $team;
    }

    private function createMember(Team $team, string $email, TeamRole $role): object
    {
        $user = new User(
            id: $this->identityProvider->nextIdentity(),
            email: $email,
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($user);

        $membership = new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $user,
            team: $team,
            role: $role,
            joinedAt: new \DateTimeImmutable(),
        );
        $this->em->persist($membership);

        return new class ($user->id, $membership->id) {
            public function __construct(
                public readonly \Ramsey\Uuid\UuidInterface $userId,
                public readonly \Ramsey\Uuid\UuidInterface $membershipId,
            ) {
            }
        };
    }
}
