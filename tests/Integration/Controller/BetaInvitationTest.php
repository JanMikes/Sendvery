<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\BetaInvitation;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\InvitationStatus;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class BetaInvitationTest extends WebTestCase
{
    #[Test]
    public function invitePageReturns200(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('GET', '/app/admin/invite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Invite Beta Users');
    }

    #[Test]
    public function inviteFormSendsInvitations(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('POST', '/app/admin/invite', [
            'emails' => "invite1@example.com\ninvite2@example.com",
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '2 invitation(s) sent');
    }

    #[Test]
    public function inviteFormRejectsInvalidEmails(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->request('POST', '/app/admin/invite', [
            'emails' => 'not-an-email',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'at least one valid email');
    }

    #[Test]
    public function acceptValidInvitationRedirectsToLogin(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $invitation = new BetaInvitation(
            id: Uuid::uuid7(),
            email: 'valid-invite@example.com',
            invitationToken: 'valid-token-'.Uuid::uuid7()->toString(),
            sentAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+7 days'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->request('GET', '/invite/'.$invitation->invitationToken);

        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/login', $location);
    }

    #[Test]
    public function acceptExpiredInvitationShowsError(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $invitation = new BetaInvitation(
            id: Uuid::uuid7(),
            email: 'expired@example.com',
            invitationToken: 'expired-token-'.Uuid::uuid7()->toString(),
            sentAt: new \DateTimeImmutable('-14 days'),
            expiresAt: new \DateTimeImmutable('-7 days'),
        );
        $em->persist($invitation);
        $em->flush();

        $client->request('GET', '/invite/'.$invitation->invitationToken);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'expired');
    }

    #[Test]
    public function acceptUsedInvitationShowsError(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $invitation = new BetaInvitation(
            id: Uuid::uuid7(),
            email: 'used@example.com',
            invitationToken: 'used-token-'.Uuid::uuid7()->toString(),
            sentAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+7 days'),
            status: InvitationStatus::Accepted,
            acceptedAt: new \DateTimeImmutable(),
        );
        $em->persist($invitation);
        $em->flush();

        $client->request('GET', '/invite/'.$invitation->invitationToken);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'already been used');
    }

    #[Test]
    public function acceptInvalidTokenShowsError(): void
    {
        $client = self::createClient();

        $client->request('GET', '/invite/nonexistent-token');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'invalid');
    }

    protected function createAuthenticatedClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'invite-admin-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Invite Test',
            slug: 'invite-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        );
        $em->persist($membership);
        $em->flush();

        $client->loginUser($user);

        return $client;
    }
}
