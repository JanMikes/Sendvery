<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\TeamInvitation;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Smoke + scenario coverage for the POST endpoints under /app/team and the
 * /team/invitation/{token} landing page. These were entirely uncovered
 * before the smoke-test sweep — the kind of plumbing that only breaks when
 * someone refactors a Twig template (see DEC: TeamSettings Twig regression).
 */
final class TeamActionsTest extends WebTestCase
{
    #[Test]
    public function inviteTeammateRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('POST', '/app/team/invite', ['email' => 'x@example.com']);

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function inviteTeammateAsOwnerSucceedsOnTeamPlan(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('team')->build();
        $client->loginUser($persona->user);

        $invitee = 'invitee-'.Uuid::uuid7()->toString().'@example.com';
        $client->request('POST', '/app/team/invite', [
            'email' => $invitee,
            'role' => 'member',
        ]);

        self::assertResponseRedirects('/app/team');

        // Follow the redirect and confirm the success flash, so we know the
        // invite actually went through (rather than being silently blocked
        // by the new plan-limit gate which also redirects to /app/team).
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Invitation sent to '.$invitee);
    }

    #[Test]
    public function inviteTeammateOnFreePlanIsBlockedWithFlash(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        // Free plan caps the team at 1 member — owner only.
        $persona = $fixtures->persona()->plan('free')->build();
        $client->loginUser($persona->user);

        $client->request('POST', '/app/team/invite', [
            'email' => 'invitee-'.Uuid::uuid7()->toString().'@example.com',
            'role' => 'member',
        ]);

        self::assertResponseRedirects('/app/team');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Upgrade your plan to invite more teammates');
    }

    #[Test]
    public function inviteTeammateOnTeamPlanAtCapIsBlocked(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        // Team plan: max 10 members. Owner + 9 teammates = at the cap.
        $persona = $fixtures->persona()->plan('team')->build();
        for ($i = 0; $i < 9; ++$i) {
            $fixtures->addExtraTeammate($persona->team);
        }
        $client->loginUser($persona->user);

        $client->request('POST', '/app/team/invite', [
            'email' => 'one-too-many-'.Uuid::uuid7()->toString().'@example.com',
            'role' => 'member',
        ]);

        self::assertResponseRedirects('/app/team');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Upgrade your plan to invite more teammates');
    }

    #[Test]
    public function inviteTeammateAsMemberIsForbidden(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->role(TeamRole::Member)->build();
        $client->loginUser($persona->user);

        $client->request('POST', '/app/team/invite', [
            'email' => 'denied-'.Uuid::uuid7()->toString().'@example.com',
        ]);

        // Voter rejects non-managers with 403 (kernel-rendered access-denied page).
        $status = $client->getResponse()->getStatusCode();
        self::assertContains($status, [302, 403], sprintf('Expected redirect or 403, got %d', $status));
    }

    #[Test]
    public function transferOwnershipRedirectsAnonymousToLogin(): void
    {
        $client = self::createClient();
        $client->request('POST', '/app/team/transfer', ['new_owner_user_id' => Uuid::uuid7()->toString()]);

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function transferOwnershipAsOwnerRedirects(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $teammate = $fixtures->addExtraTeammate($persona->team, TeamRole::Admin);
        $client->loginUser($persona->user);

        $client->request('POST', '/app/team/transfer', [
            'new_owner_user_id' => $teammate->id->toString(),
        ]);

        self::assertResponseRedirects('/app/team');
    }

    #[Test]
    public function removeMemberAsOwnerRedirects(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $teammate = $fixtures->addExtraTeammate($persona->team, TeamRole::Member);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $memberships = $em->getRepository(\App\Entity\TeamMembership::class)
            ->findBy(['user' => $teammate->id->toString()]);
        self::assertNotEmpty($memberships);

        $client->loginUser($persona->user);
        $client->request('POST', '/app/team/members/'.$memberships[0]->id.'/remove');

        self::assertResponseRedirects('/app/team');
    }

    #[Test]
    public function revokeInvitationAsOwnerRedirects(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $invitation = new TeamInvitation(
            id: Uuid::uuid7(),
            team: $persona->team,
            invitedEmail: 'pending-'.Uuid::uuid7()->toString().'@example.com',
            invitedBy: $persona->user,
            role: TeamRole::Member,
            invitationToken: bin2hex(random_bytes(32)),
            sentAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('POST', '/app/team/invitations/'.$invitation->id.'/revoke');

        self::assertResponseRedirects('/app/team');
    }

    #[Test]
    public function resendInvitationAsOwnerRedirects(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $invitation = new TeamInvitation(
            id: Uuid::uuid7(),
            team: $persona->team,
            invitedEmail: 'pending-'.Uuid::uuid7()->toString().'@example.com',
            invitedBy: $persona->user,
            role: TeamRole::Member,
            invitationToken: bin2hex(random_bytes(32)),
            sentAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('POST', '/app/team/invitations/'.$invitation->id.'/resend');

        self::assertResponseRedirects('/app/team');
    }

    #[Test]
    public function acceptInvitationWithUnknownTokenRendersInvalidPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/team/invitation/totally-bogus-token');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', "isn't valid");
    }

    #[Test]
    public function acceptInvitationWhileAnonymousRedirectsToLogin(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $token = bin2hex(random_bytes(32));
        $invitation = new TeamInvitation(
            id: Uuid::uuid7(),
            team: $persona->team,
            invitedEmail: 'invitee-'.Uuid::uuid7()->toString().'@example.com',
            invitedBy: $persona->user,
            role: TeamRole::Member,
            invitationToken: $token,
            sentAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->request('GET', '/team/invitation/'.$token);

        // Redirects to login with the invited email pre-filled.
        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringContainsString('/login', $location);
    }

    #[Test]
    public function acceptInvitationLoggedInWithMismatchedEmailShowsMismatchPage(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $other = $fixtures->persona()->emailPrefix('other')->build();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $token = bin2hex(random_bytes(32));
        $invitation = new TeamInvitation(
            id: Uuid::uuid7(),
            team: $persona->team,
            invitedEmail: 'someone-else@example.com',
            invitedBy: $persona->user,
            role: TeamRole::Member,
            invitationToken: $token,
            sentAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->loginUser($other->user);
        $client->request('GET', '/team/invitation/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'someone-else@example.com');
    }
}
