<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class OnboardingTest extends WebTestCase
{
    #[Test]
    public function onboardingTeamPageReturns200ForNewUser(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam();

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/team');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function onboardingTeamRedirectsToDashboardIfCompleted(): void
    {
        $client = self::createClient();
        $user = $this->createCompletedUser();

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/team');

        self::assertResponseRedirects('/app');
    }

    #[Test]
    public function onboardingTeamPostUpdatesTeamName(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam();

        $client->loginUser($user);
        $client->request('POST', '/app/onboarding/team', ['team_name' => 'My Custom Team']);

        self::assertResponseRedirects('/app/onboarding/domain');
    }

    #[Test]
    public function onboardingDomainPageReturns200(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam();

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/domain');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function onboardingIngestionPageReturns200(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam();

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function onboardingIngestionForwardMethodRedirectsToComplete(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam();

        $client->loginUser($user);
        $client->request('POST', '/app/onboarding/ingestion', [
            'method' => 'forward',
        ]);

        self::assertResponseRedirects('/app/onboarding/complete');
    }

    #[Test]
    public function onboardingCompleteSetsOnboardingCompleted(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam();

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/complete');

        self::assertResponseIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();

        $refreshed = $em->find(User::class, $user->id);
        self::assertNotNull($refreshed);
        self::assertNotNull($refreshed->onboardingCompletedAt);
    }

    #[Test]
    public function dashboardRedirectsToOnboardingForIncompleteUser(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam();

        $client->loginUser($user);
        $client->request('GET', '/app');

        self::assertResponseRedirects('/app/onboarding/team');
    }

    #[Test]
    public function dashboardAccessibleAfterOnboarding(): void
    {
        $client = self::createClient();
        $user = $this->createCompletedUser();

        $client->loginUser($user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function onboardingPagesNotAccessibleWithoutAuth(): void
    {
        $client = self::createClient();

        $client->request('GET', '/app/onboarding/team');
        self::assertResponseRedirects('/login');
    }

    private function createNewUserWithTeam(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'onboard-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'example.com',
            slug: 'onboard-'.substr($teamId->toString(), 0, 8),
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

        return $user;
    }

    private function createCompletedUser(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'completed-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Completed Team',
            slug: 'completed-'.substr($teamId->toString(), 0, 8),
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

        return $user;
    }
}
