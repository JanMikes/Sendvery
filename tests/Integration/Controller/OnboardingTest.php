<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MonitoredDomain;
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

    /**
     * Reproduces the readonly-id Doctrine proxy bug: a fresh request has no
     * entity-map cache, so TeamMembership::$team is loaded as a proxy.
     * Touching $team->name triggers proxy init, which used to throw
     * LogicException on the readonly $id property because Ramsey UUIDs are
     * compared by object identity.
     */
    #[Test]
    public function onboardingTeamPageWorksWhenTeamLoadedAsProxy(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();

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

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();

        $refreshed = $em->find(User::class, $user->id);
        self::assertNotNull($refreshed);
        self::assertNotNull($refreshed->onboardingTeamCompletedAt);
    }

    #[Test]
    public function onboardingTeamPagePrefillsCurrentTeamName(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam(teamStepCompleted: true);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/app/onboarding/team');

        self::assertResponseIsSuccessful();
        self::assertSame('example.com', $crawler->filter('#team_name')->attr('value'));
    }

    #[Test]
    public function onboardingTeamPageStillRendersWhenRevisitedAfterCompletingTeamStep(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam(teamStepCompleted: true, withDomain: true);

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/team');

        self::assertResponseIsSuccessful();
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
    public function onboardingDomainPageStillRendersWhenRevisitedWithExistingDomain(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam(teamStepCompleted: true, withDomain: true);

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/domain');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function dashboardRedirectsMidFlowUserWithDomainToIngestion(): void
    {
        $client = self::createClient();
        // Mirrors the production bug: user has a domain but onboardingTeamCompletedAt is null
        // (column didn't exist when they started). They should land on step 3, not step 1.
        $user = $this->createNewUserWithTeam(teamStepCompleted: false, withDomain: true);

        $client->loginUser($user);
        $client->request('GET', '/app');

        self::assertResponseRedirects('/app/onboarding/ingestion');
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
    public function onboardingIngestionShowsRequestedDomainNotOldest(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = $this->createNewUserWithTeam(teamStepCompleted: true);

        $membership = $em->getRepository(TeamMembership::class)->findOneBy(['user' => $user->id->toString()]);
        self::assertNotNull($membership);
        $team = $membership->team;

        $older = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'older-domain.example',
            createdAt: new \DateTimeImmutable('2026-01-01 10:00:00'),
        );
        $older->popEvents();
        $em->persist($older);

        $newer = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'newer-domain.example',
            createdAt: new \DateTimeImmutable('2026-01-02 10:00:00'),
        );
        $newer->popEvents();
        $em->persist($newer);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion?domain=newer-domain.example');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('newer-domain.example', $body);
        self::assertStringNotContainsString('older-domain.example', $body);
    }

    #[Test]
    public function onboardingIngestionFallsBackToLatestDomainWhenNoQueryParam(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = $this->createNewUserWithTeam(teamStepCompleted: true);

        $membership = $em->getRepository(TeamMembership::class)->findOneBy(['user' => $user->id->toString()]);
        self::assertNotNull($membership);
        $team = $membership->team;

        $older = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'older-fallback.example',
            createdAt: new \DateTimeImmutable('2026-01-01 10:00:00'),
        );
        $older->popEvents();
        $em->persist($older);

        $newer = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'newer-fallback.example',
            createdAt: new \DateTimeImmutable('2026-01-02 10:00:00'),
        );
        $newer->popEvents();
        $em->persist($newer);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/app/onboarding/ingestion');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('newer-fallback.example', $body);
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
    public function dashboardRedirectsToDomainStepWhenTeamStepDoneButNoDomain(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam(teamStepCompleted: true);

        $client->loginUser($user);
        $client->request('GET', '/app');

        self::assertResponseRedirects('/app/onboarding/domain');
    }

    #[Test]
    public function dashboardRedirectsToIngestionStepWhenDomainExistsButOnboardingIncomplete(): void
    {
        $client = self::createClient();
        $user = $this->createNewUserWithTeam(teamStepCompleted: true, withDomain: true);

        $client->loginUser($user);
        $client->request('GET', '/app');

        self::assertResponseRedirects('/app/onboarding/ingestion');
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

    private function createNewUserWithTeam(bool $teamStepCompleted = false, bool $withDomain = false): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'onboard-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingTeamCompletedAt: $teamStepCompleted ? new \DateTimeImmutable() : null,
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

        if ($withDomain) {
            $domain = new MonitoredDomain(
                id: Uuid::uuid7(),
                team: $team,
                domain: 'onboard-'.substr($teamId->toString(), 0, 8).'.example',
                createdAt: new \DateTimeImmutable(),
            );
            $domain->popEvents();
            $em->persist($domain);
        }

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

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'completed-'.substr($teamId->toString(), 0, 8).'.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $em->flush();

        return $user;
    }
}
