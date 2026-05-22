<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Entity\TeamInvitation;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Exceptions\InvitationEmailMismatch;
use App\Exceptions\InvitationNoLongerAcceptable;
use App\Exceptions\TeamInvitationNotFound;
use App\Exceptions\UserAlreadyOnTeam;
use App\Message\AcceptTeamInvitation;
use App\MessageHandler\AcceptTeamInvitationHandler;
use App\Repository\TeamMembershipRepository;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\TeamInvitationStatus;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class AcceptTeamInvitationHandlerTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private AcceptTeamInvitationHandler $handler;
    private IdentityProvider $identityProvider;
    private TeamMembershipRepository $membershipRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->handler = $this->getService(AcceptTeamInvitationHandler::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
        $this->membershipRepository = $this->getService(TeamMembershipRepository::class);
    }

    public function testCreatesMembershipAndMarksInvitationAccepted(): void
    {
        $team = $this->createTeam();
        $invitedUser = $this->createUser('accept-test@example.com');
        $invitation = $this->createInvitation($team, $this->createUser('owner-accept@example.com'), 'accept-test@example.com');
        $this->em->flush();

        ($this->handler)(new AcceptTeamInvitation(
            invitationToken: $invitation->invitationToken,
            acceptingUserId: $invitedUser->id,
        ));

        $this->em->flush();
        $this->em->clear();

        $membership = $this->membershipRepository->findMembership($invitedUser->id, $team->id);
        self::assertNotNull($membership);
        self::assertSame(TeamRole::Member, $membership->role);

        $reloaded = $this->em->find(TeamInvitation::class, $invitation->id);
        self::assertNotNull($reloaded);
        self::assertSame(TeamInvitationStatus::Accepted, $reloaded->status);
        self::assertNotNull($reloaded->acceptedAt);
    }

    public function testThrowsWhenTokenUnknown(): void
    {
        $invitedUser = $this->createUser('whoever@example.com');
        $this->em->flush();

        $this->expectException(TeamInvitationNotFound::class);

        ($this->handler)(new AcceptTeamInvitation(
            invitationToken: 'no-such-token',
            acceptingUserId: $invitedUser->id,
        ));
    }

    public function testThrowsOnEmailMismatch(): void
    {
        $team = $this->createTeam();
        $invitedUser = $this->createUser('different@example.com');
        $invitation = $this->createInvitation($team, $this->createUser('o@example.com'), 'invited@example.com');
        $this->em->flush();

        $this->expectException(InvitationEmailMismatch::class);

        ($this->handler)(new AcceptTeamInvitation(
            invitationToken: $invitation->invitationToken,
            acceptingUserId: $invitedUser->id,
        ));
    }

    public function testThrowsWhenInvitationExpired(): void
    {
        $team = $this->createTeam();
        $invitedUser = $this->createUser('expired@example.com');
        $invitation = $this->createInvitation(
            $team,
            $this->createUser('o-expired@example.com'),
            'expired@example.com',
            expiresAt: new \DateTimeImmutable('-1 day'),
        );
        $this->em->flush();

        $this->expectException(InvitationNoLongerAcceptable::class);

        ($this->handler)(new AcceptTeamInvitation(
            invitationToken: $invitation->invitationToken,
            acceptingUserId: $invitedUser->id,
        ));
    }

    public function testThrowsWhenUserAlreadyOnTeam(): void
    {
        $team = $this->createTeam();
        $invitedUser = $this->createUser('already@example.com');

        $this->em->persist(new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $invitedUser,
            team: $team,
            role: TeamRole::Member,
            joinedAt: new \DateTimeImmutable(),
        ));
        $invitation = $this->createInvitation($team, $this->createUser('o-already@example.com'), 'already@example.com');
        $this->em->flush();

        $this->expectException(UserAlreadyOnTeam::class);

        ($this->handler)(new AcceptTeamInvitation(
            invitationToken: $invitation->invitationToken,
            acceptingUserId: $invitedUser->id,
        ));
    }

    private function createTeam(): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Accept Test',
            slug: 'accept-'.Uuid::uuid7()->toString(),
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

    private function createInvitation(
        Team $team,
        User $invitedBy,
        string $invitedEmail,
        ?\DateTimeImmutable $expiresAt = null,
    ): TeamInvitation {
        $invitation = new TeamInvitation(
            id: $this->identityProvider->nextIdentity(),
            team: $team,
            invitedEmail: $invitedEmail,
            invitedBy: $invitedBy,
            role: TeamRole::Member,
            invitationToken: bin2hex(random_bytes(32)),
            sentAt: new \DateTimeImmutable(),
            expiresAt: $expiresAt ?? new \DateTimeImmutable('+14 days'),
        );
        $this->em->persist($invitation);

        return $invitation;
    }
}
