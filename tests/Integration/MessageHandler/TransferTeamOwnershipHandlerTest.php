<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Exceptions\CannotTransferOwnership;
use App\Message\TransferTeamOwnership;
use App\MessageHandler\TransferTeamOwnershipHandler;
use App\Repository\TeamMembershipRepository;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class TransferTeamOwnershipHandlerTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private TransferTeamOwnershipHandler $handler;
    private TeamMembershipRepository $membershipRepository;
    private IdentityProvider $identityProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->handler = $this->getService(TransferTeamOwnershipHandler::class);
        $this->membershipRepository = $this->getService(TeamMembershipRepository::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
    }

    public function testSwapsOwnerAndAdminRoles(): void
    {
        $team = $this->createTeam();
        $owner = $this->createMember($team, 'owner@example.com', TeamRole::Owner);
        $newOwner = $this->createMember($team, 'newowner@example.com', TeamRole::Member);
        $this->em->flush();

        ($this->handler)(new TransferTeamOwnership(
            teamId: $team->id,
            newOwnerUserId: $newOwner->id,
            currentOwnerUserId: $owner->id,
        ));

        $this->em->flush();
        $this->em->clear();

        $previousOwnership = $this->membershipRepository->findMembership($owner->id, $team->id);
        $newOwnership = $this->membershipRepository->findMembership($newOwner->id, $team->id);

        self::assertNotNull($previousOwnership);
        self::assertNotNull($newOwnership);
        self::assertSame(TeamRole::Admin, $previousOwnership->role);
        self::assertSame(TeamRole::Owner, $newOwnership->role);
    }

    public function testRefusesSelfTransfer(): void
    {
        $team = $this->createTeam();
        $owner = $this->createMember($team, 'self@example.com', TeamRole::Owner);
        $this->em->flush();

        $this->expectException(CannotTransferOwnership::class);

        ($this->handler)(new TransferTeamOwnership(
            teamId: $team->id,
            newOwnerUserId: $owner->id,
            currentOwnerUserId: $owner->id,
        ));
    }

    public function testRefusesIfCurrentOwnerIsNotActuallyOwner(): void
    {
        $team = $this->createTeam();
        $imposter = $this->createMember($team, 'imposter@example.com', TeamRole::Admin);
        $target = $this->createMember($team, 'target@example.com', TeamRole::Member);
        $this->em->flush();

        $this->expectException(CannotTransferOwnership::class);

        ($this->handler)(new TransferTeamOwnership(
            teamId: $team->id,
            newOwnerUserId: $target->id,
            currentOwnerUserId: $imposter->id,
        ));
    }

    public function testRefusesIfNewOwnerIsNotAMember(): void
    {
        $team = $this->createTeam();
        $owner = $this->createMember($team, 'real-owner@example.com', TeamRole::Owner);
        $stranger = $this->createUser('stranger@example.com');
        $this->em->flush();

        $this->expectException(CannotTransferOwnership::class);

        ($this->handler)(new TransferTeamOwnership(
            teamId: $team->id,
            newOwnerUserId: $stranger->id,
            currentOwnerUserId: $owner->id,
        ));
    }

    private function createTeam(): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Transfer Test',
            slug: 'transfer-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($team);

        return $team;
    }

    private function createUser(string $email): User
    {
        $user = new User(
            id: $this->identityProvider->nextIdentity(),
            email: $email,
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($user);

        return $user;
    }

    private function createMember(Team $team, string $email, TeamRole $role): User
    {
        $user = $this->createUser($email);
        $this->em->persist(new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $user,
            team: $team,
            role: $role,
            joinedAt: new \DateTimeImmutable(),
        ));

        return $user;
    }
}
