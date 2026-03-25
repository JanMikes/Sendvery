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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class BillingPagesTest extends WebTestCase
{
    /** @return array{client: KernelBrowser, teamId: \Ramsey\Uuid\UuidInterface} */
    private function createAuthenticatedClientWithTeam(string $plan = 'free'): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'billing-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Billing Test',
            slug: 'billing-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan,
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

        return ['client' => $client, 'teamId' => $teamId];
    }

    #[Test]
    public function billingPageReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithTeam();

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Current Plan');
        self::assertSelectorTextContains('body', 'Free');
    }

    #[Test]
    public function billingPageShowsUsage(): void
    {
        $data = $this->createAuthenticatedClientWithTeam();

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Domains');
        self::assertSelectorTextContains('body', 'Team Members');
    }

    #[Test]
    public function billingPageShowsUpgradeOptionsForFreePlan(): void
    {
        $data = $this->createAuthenticatedClientWithTeam('free');

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Upgrade Your Plan');
        self::assertSelectorTextContains('body', 'Personal');
        self::assertSelectorTextContains('body', 'Team');
    }

    #[Test]
    public function billingPageShowsTeamUpgradeForPersonalPlan(): void
    {
        $data = $this->createAuthenticatedClientWithTeam('personal');

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Upgrade Your Plan');
        self::assertSelectorTextContains('body', 'Team');
    }

    #[Test]
    public function billingSuccessReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithTeam();

        $data['client']->request('GET', '/app/settings/billing/success');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Welcome to your new plan');
    }

    #[Test]
    public function billingCancelReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithTeam();

        $data['client']->request('GET', '/app/settings/billing/cancel');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No changes made');
    }

    #[Test]
    public function upgradeWithInvalidPlanRedirectsToBilling(): void
    {
        $data = $this->createAuthenticatedClientWithTeam();

        $data['client']->request('GET', '/app/settings/billing/upgrade/free');

        self::assertResponseRedirects('/app/settings/billing');
    }

    #[Test]
    public function domainLimitEnforcedOnAddDomain(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'limit-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Limit Test',
            slug: 'limit-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: 'free',
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

        // Create 1 domain (free plan limit)
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'existing.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        $client->loginUser($user);

        // Try to add another domain
        $client->request('POST', '/app/domains/add', [
            'domain_name' => 'new-domain.com',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'domain limit');
    }

    #[Test]
    public function addDomainShowsUpgradePromptWhenAtLimit(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'prompt-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Prompt Test',
            slug: 'prompt-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: 'free',
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
            domain: 'existing-prompt.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        $client->loginUser($user);

        $client->request('GET', '/app/domains/add');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Domain limit reached');
        self::assertSelectorTextContains('body', 'Upgrade plan');
    }

    #[Test]
    public function billingPageRequiresAuth(): void
    {
        $client = self::createClient();

        $client->request('GET', '/app/settings/billing');

        self::assertResponseRedirects();
    }

    #[Test]
    public function dashboardSidebarContainsBillingLink(): void
    {
        $data = $this->createAuthenticatedClientWithTeam();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('aside', 'Billing');
    }
}
