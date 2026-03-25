<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Repository\TeamMembershipRepository;
use App\Security\TeamVoter;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class TeamVoterTest extends TestCase
{
    private function createTeamAndUser(): array
    {
        $now = new \DateTimeImmutable();
        $user = new User(id: Uuid::uuid7(), email: 'user@test.com', createdAt: $now);
        $team = new Team(id: Uuid::uuid7(), name: 'Test', slug: 'test', createdAt: $now);

        return [$user, $team];
    }

    private function createToken(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function createMembership(User $user, Team $team, TeamRole $role): TeamMembership
    {
        return new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: $role,
            joinedAt: new \DateTimeImmutable(),
        );
    }

    private function createVoterWithMembership(?TeamMembership $membership): TeamVoter
    {
        $doctrineRepo = $this->createStub(EntityRepository::class);
        $doctrineRepo->method('findOneBy')->willReturn($membership);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($doctrineRepo);

        return new TeamVoter(new TeamMembershipRepository($em));
    }

    public function testOwnerCanDoEverything(): void
    {
        [$user, $team] = $this->createTeamAndUser();
        $membership = $this->createMembership($user, $team, TeamRole::Owner);
        $voter = $this->createVoterWithMembership($membership);
        $token = $this->createToken($user);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $team, [TeamVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $team, [TeamVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $team, [TeamVoter::DELETE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $team, [TeamVoter::MANAGE_MEMBERS]));
    }

    public function testAdminCanViewEditAndManageButNotDelete(): void
    {
        [$user, $team] = $this->createTeamAndUser();
        $membership = $this->createMembership($user, $team, TeamRole::Admin);
        $voter = $this->createVoterWithMembership($membership);
        $token = $this->createToken($user);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $team, [TeamVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $team, [TeamVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::DELETE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $team, [TeamVoter::MANAGE_MEMBERS]));
    }

    public function testMemberCanOnlyView(): void
    {
        [$user, $team] = $this->createTeamAndUser();
        $membership = $this->createMembership($user, $team, TeamRole::Member);
        $voter = $this->createVoterWithMembership($membership);
        $token = $this->createToken($user);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $team, [TeamVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::DELETE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::MANAGE_MEMBERS]));
    }

    public function testViewerCanOnlyView(): void
    {
        [$user, $team] = $this->createTeamAndUser();
        $membership = $this->createMembership($user, $team, TeamRole::Viewer);
        $voter = $this->createVoterWithMembership($membership);
        $token = $this->createToken($user);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $team, [TeamVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::DELETE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::MANAGE_MEMBERS]));
    }

    public function testNonMemberIsDeniedEverything(): void
    {
        [$user, $team] = $this->createTeamAndUser();
        $voter = $this->createVoterWithMembership(null);
        $token = $this->createToken($user);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::DELETE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::MANAGE_MEMBERS]));
    }

    public function testNonUserTokenIsDenied(): void
    {
        [, $team] = $this->createTeamAndUser();
        $voter = $this->createVoterWithMembership(null);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $team, [TeamVoter::VIEW]));
    }

    public function testUnsupportedAttributeAbstains(): void
    {
        [$user, $team] = $this->createTeamAndUser();
        $voter = $this->createVoterWithMembership(null);
        $token = $this->createToken($user);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $team, ['UNSUPPORTED']));
    }

    public function testNonTeamSubjectAbstains(): void
    {
        [$user] = $this->createTeamAndUser();
        $voter = $this->createVoterWithMembership(null);
        $token = $this->createToken($user);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new \stdClass(), [TeamVoter::VIEW]));
    }
}
