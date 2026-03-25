<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MagicLinkToken;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class AuthTest extends WebTestCase
{
    #[Test]
    public function loginPageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="email"]');
    }

    #[Test]
    public function loginPageHasForm(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertSelectorExists('form');
        self::assertSelectorExists('input[name="email"]');
        self::assertSelectorExists('button[type="submit"]');
    }

    #[Test]
    public function submitValidEmailShowsCheckEmailPage(): void
    {
        $client = self::createClient();
        $email = 'login-'.Uuid::uuid7()->toString().'@example.com';

        $client->request('POST', '/login', ['email' => $email]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Check your email');
    }

    #[Test]
    public function submitInvalidEmailShowsError(): void
    {
        $client = self::createClient();

        $client->request('POST', '/login', ['email' => 'not-an-email']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-error');
    }

    #[Test]
    public function submitEmptyEmailShowsError(): void
    {
        $client = self::createClient();

        $client->request('POST', '/login', ['email' => '']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-error');
    }

    #[Test]
    public function verifyValidTokenLogsInExistingUser(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Create user + team + membership
        $userId = Uuid::uuid7();
        $email = 'auth-existing-'.$userId->toString().'@example.com';
        $user = new User(
            id: $userId,
            email: $email,
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Test Team',
            slug: 'auth-test-'.substr($teamId->toString(), 0, 8),
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

        // Create valid token
        $tokenString = bin2hex(random_bytes(32));
        $token = new MagicLinkToken(
            id: Uuid::uuid7(),
            email: $email,
            token: $tokenString,
            expiresAt: new \DateTimeImmutable('+15 minutes'),
            createdAt: new \DateTimeImmutable(),
            user: $user,
        );
        $em->persist($token);
        $em->flush();

        $client->request('GET', '/login/verify/'.$tokenString);

        self::assertResponseRedirects('/app');
    }

    #[Test]
    public function verifyValidTokenCreatesNewUserAndRedirectsToOnboarding(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $email = 'auth-new-'.Uuid::uuid7()->toString().'@example.com';
        $tokenString = bin2hex(random_bytes(32));

        $token = new MagicLinkToken(
            id: Uuid::uuid7(),
            email: $email,
            token: $tokenString,
            expiresAt: new \DateTimeImmutable('+15 minutes'),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($token);
        $em->flush();

        $client->request('GET', '/login/verify/'.$tokenString);

        self::assertResponseRedirects('/app/onboarding/team');

        // Verify user was created
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        // Verify team was created
        $memberships = $em->getRepository(TeamMembership::class)->findBy(['user' => $user->id->toString()]);
        self::assertCount(1, $memberships);
        self::assertSame(TeamRole::Owner, $memberships[0]->role);
    }

    #[Test]
    public function verifyExpiredTokenShowsError(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $tokenString = bin2hex(random_bytes(32));
        $token = new MagicLinkToken(
            id: Uuid::uuid7(),
            email: 'expired@example.com',
            token: $tokenString,
            expiresAt: new \DateTimeImmutable('-1 hour'),
            createdAt: new \DateTimeImmutable('-2 hours'),
        );
        $em->persist($token);
        $em->flush();

        $client->request('GET', '/login/verify/'.$tokenString);

        self::assertResponseRedirects('/login/failed');
    }

    #[Test]
    public function verifyUsedTokenShowsError(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $tokenString = bin2hex(random_bytes(32));
        $token = new MagicLinkToken(
            id: Uuid::uuid7(),
            email: 'used@example.com',
            token: $tokenString,
            expiresAt: new \DateTimeImmutable('+15 minutes'),
            createdAt: new \DateTimeImmutable(),
            usedAt: new \DateTimeImmutable(),
        );
        $em->persist($token);
        $em->flush();

        $client->request('GET', '/login/verify/'.$tokenString);

        self::assertResponseRedirects('/login/failed');
    }

    #[Test]
    public function verifyInvalidTokenShowsError(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login/verify/nonexistenttoken');

        self::assertResponseRedirects('/login/failed');
    }

    #[Test]
    public function dashboardWithoutAuthRedirectsToLogin(): void
    {
        $client = self::createClient();

        $client->request('GET', '/app');

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function loginFailedPageReturns200(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login/failed');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function authenticatedUserOnLoginPageRedirectsToDashboard(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Create user with completed onboarding
        $userId = Uuid::uuid7();
        $email = 'already-auth-'.$userId->toString().'@example.com';
        $user = new User(
            id: $userId,
            email: $email,
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Auth Team',
            slug: 'already-auth-'.substr($teamId->toString(), 0, 8),
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

        $client->request('GET', '/login');
        self::assertResponseRedirects('/app');
    }
}
