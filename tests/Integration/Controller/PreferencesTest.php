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

final class PreferencesTest extends WebTestCase
{
    #[Test]
    public function preferencesPageReturns200(): void
    {
        ['client' => $client] = $this->createAuthenticatedUser();

        $client->request('GET', '/app/settings/preferences');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Email Preferences');
    }

    #[Test]
    public function preferencesFormShowsCurrentValues(): void
    {
        ['client' => $client] = $this->createAuthenticatedUser();

        $client->request('GET', '/app/settings/preferences');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Weekly Digest Email');
        self::assertSelectorTextContains('body', 'Alert Notifications');
    }

    #[Test]
    public function preferencesCanBeSaved(): void
    {
        ['client' => $client, 'userId' => $userId] = $this->createAuthenticatedUser();

        $client->request('POST', '/app/settings/preferences', [
            'email_digest_enabled' => '0',
            'email_alerts_enabled' => '0',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Preferences saved');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $user = $em->find(User::class, $userId);
        self::assertNotNull($user);
        self::assertFalse($user->emailDigestEnabled);
        self::assertFalse($user->emailAlertsEnabled);
    }

    #[Test]
    public function preferencesPageUsesDashboardLayout(): void
    {
        ['client' => $client] = $this->createAuthenticatedUser();

        $client->request('GET', '/app/settings/preferences');

        self::assertSelectorExists('aside');
    }

    /**
     * @return array{client: \Symfony\Bundle\FrameworkBundle\KernelBrowser, userId: \Ramsey\Uuid\UuidInterface}
     */
    private function createAuthenticatedUser(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'pref-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Pref Test',
            slug: 'pref-test-'.Uuid::uuid7()->toString(),
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

        return ['client' => $client, 'userId' => $userId];
    }
}
