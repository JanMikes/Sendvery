<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Entity\TeamInvitation;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Exceptions\UserAlreadyOnTeam;
use App\Message\InviteTeammate;
use App\MessageHandler\InviteTeammateHandler;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\TeamInvitationStatus;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class InviteTeammateHandlerTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private InviteTeammateHandler $handler;
    private IdentityProvider $identityProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->handler = $this->getService(InviteTeammateHandler::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
    }

    public function testCreatesPendingInvitation(): void
    {
        [$team, $owner] = $this->seedTeamWithOwner();
        $this->em->flush();

        ($this->handler)(new InviteTeammate(
            invitationId: $this->identityProvider->nextIdentity(),
            teamId: $team->id,
            invitedByUserId: $owner->id,
            invitedEmail: 'colleague@example.com',
            role: TeamRole::Member,
        ));

        $this->em->flush();
        $this->em->clear();

        $invitation = $this->em->getRepository(TeamInvitation::class)
            ->findOneBy(['invitedEmail' => 'colleague@example.com']);
        self::assertNotNull($invitation);
        self::assertSame(TeamInvitationStatus::Pending, $invitation->status);
        self::assertSame(TeamRole::Member, $invitation->role);
        self::assertGreaterThan(60, strlen($invitation->invitationToken), 'token must be long enough to be hard to guess');
    }

    public function testNormalizesEmailToLowercase(): void
    {
        [$team, $owner] = $this->seedTeamWithOwner();
        $this->em->flush();

        ($this->handler)(new InviteTeammate(
            invitationId: $this->identityProvider->nextIdentity(),
            teamId: $team->id,
            invitedByUserId: $owner->id,
            invitedEmail: '  Mixed@Case.Com  ',
            role: TeamRole::Member,
        ));

        $this->em->flush();
        $this->em->clear();

        $invitation = $this->em->getRepository(TeamInvitation::class)
            ->findOneBy(['invitedEmail' => 'mixed@case.com']);
        self::assertNotNull($invitation);
    }

    public function testRefusesToInviteExistingTeamMember(): void
    {
        [$team, $owner] = $this->seedTeamWithOwner();
        $member = $this->createUser('teammate@example.com');
        $this->em->persist(new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $member,
            team: $team,
            role: TeamRole::Member,
            joinedAt: new \DateTimeImmutable(),
        ));
        $this->em->flush();

        $this->expectException(UserAlreadyOnTeam::class);

        ($this->handler)(new InviteTeammate(
            invitationId: $this->identityProvider->nextIdentity(),
            teamId: $team->id,
            invitedByUserId: $owner->id,
            invitedEmail: 'teammate@example.com',
            role: TeamRole::Member,
        ));
    }

    public function testRefusesDuplicatePendingInvitation(): void
    {
        [$team, $owner] = $this->seedTeamWithOwner();
        $this->em->flush();

        ($this->handler)(new InviteTeammate(
            invitationId: $this->identityProvider->nextIdentity(),
            teamId: $team->id,
            invitedByUserId: $owner->id,
            invitedEmail: 'dup@example.com',
            role: TeamRole::Member,
        ));
        $this->em->flush();

        $this->expectException(UserAlreadyOnTeam::class);

        ($this->handler)(new InviteTeammate(
            invitationId: $this->identityProvider->nextIdentity(),
            teamId: $team->id,
            invitedByUserId: $owner->id,
            invitedEmail: 'dup@example.com',
            role: TeamRole::Admin,
        ));
    }

    /** @return array{0: Team, 1: User} */
    private function seedTeamWithOwner(): array
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Invite Test',
            slug: 'invite-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($team);

        $owner = $this->createUser('owner-'.Uuid::uuid7()->toString().'@example.com');
        $this->em->persist(new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $owner,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        return [$team, $owner];
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
}
