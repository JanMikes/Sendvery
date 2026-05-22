<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use App\Value\TeamRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class BillingPagesTest extends WebTestCase
{
    /** @return array{client: KernelBrowser, teamId: UuidInterface, domain: MonitoredDomain} */
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

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'billing-'.substr($teamId->toString(), 0, 8).'.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $em->flush();

        $client->loginUser($user);

        return ['client' => $client, 'teamId' => $teamId, 'domain' => $domain];
    }

    private function insertTeamUsage(UuidInterface $teamId, int $count, string $startsAt = '2026-05-01 00:00:00', string $endsAt = '2026-06-01 00:00:00'): void
    {
        $connection = self::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);
        $connection->executeStatement(
            'INSERT INTO team_usage (team_id, reports_parsed_count, period_started_at, period_ends_at)
             VALUES (:teamId, :count, :startsAt, :endsAt)',
            [
                'teamId' => $teamId->toString(),
                'count' => $count,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
            ],
        );
    }

    private function createQuarantine(string $domainName, QuarantineReason $reason): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<envelope-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Report',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $em->persist($envelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $em->persist(new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: $reason,
            reportXmlGz: $compressed,
        ));
        $em->flush();
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
        self::assertSelectorTextContains('body', 'Change your plan');
        self::assertSelectorTextContains('body', 'Upgrade to Personal');
    }

    #[Test]
    public function billingPageShowsProUpgradeForPersonalPlan(): void
    {
        $data = $this->createAuthenticatedClientWithTeam('personal');

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Change your plan');
        self::assertSelectorTextContains('body', 'Upgrade to Pro');
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

    #[Test]
    public function billingPageShowsMonthlyReportsPanel(): void
    {
        $data = $this->createAuthenticatedClientWithTeam('personal');
        $this->insertTeamUsage($data['teamId'], 250);

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Monthly reports', $body);
        self::assertStringContainsString('250 / 1000', $body);
        self::assertStringContainsString('Resets', $body);
    }

    #[Test]
    public function billingPageHidesPanelOnUnlimitedPlan(): void
    {
        $data = $this->createAuthenticatedClientWithTeam('unlimited');
        $this->insertTeamUsage($data['teamId'], 1_000_000);

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // The "Monthly reports" panel must not render when ingestion is unlimited.
        self::assertStringNotContainsString('Monthly reports', $body);
    }

    #[Test]
    public function billingPageShowsPlanOverageWarning(): void
    {
        $data = $this->createAuthenticatedClientWithTeam('free');
        $this->insertTeamUsage($data['teamId'], 100);
        $this->createQuarantine($data['domain']->domain, QuarantineReason::PlanOverage);
        $this->createQuarantine($data['domain']->domain, QuarantineReason::PlanOverage);

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('2 reports waiting', $body);
        self::assertStringContainsString("hit this month's cap", $body);
    }

    #[Test]
    public function billingPageHidesWarningWhenZeroOverage(): void
    {
        $data = $this->createAuthenticatedClientWithTeam('free');
        $this->insertTeamUsage($data['teamId'], 50);

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringNotContainsString('reports waiting', $body);
    }

    #[Test]
    public function billingPageShowsRetentionSubLine(): void
    {
        $data = $this->createAuthenticatedClientWithTeam('free');
        $this->insertTeamUsage($data['teamId'], 10);

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Retention', $body);
        self::assertStringContainsString('30 days', $body);
    }

    #[Test]
    public function billingPageShowsRetentionUpsellNudge(): void
    {
        $data = $this->createAuthenticatedClientWithTeam('free');
        $this->insertTeamUsage($data['teamId'], 10);

        $data['client']->request('GET', '/app/settings/billing');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Upgrade to Personal for 1-year retention', $body);
    }
}
