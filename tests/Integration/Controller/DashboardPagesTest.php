<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class DashboardPagesTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, domainId: \Ramsey\Uuid\UuidInterface, reportId: \Ramsey\Uuid\UuidInterface}
     */
    private function createAuthenticatedClientWithData(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'dash-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Dashboard Test',
            slug: 'dashboard-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable('2000-01-01'),
            plan: 'personal',
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

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'dashboard-test.com',
            createdAt: new \DateTimeImmutable(),
            dmarcPolicy: DmarcPolicy::Reject,
        );
        $domain->popEvents();
        $em->persist($domain);

        $reportId = Uuid::uuid7();
        $report = new DmarcReport(
            id: $reportId,
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-dash-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: 'dashboard-test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $record = new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'dashboard-test.com',
        );
        $em->persist($record);
        $em->flush();

        $client->loginUser($user);

        return [
            'client' => $client,
            'domainId' => $domainId,
            'reportId' => $reportId,
        ];
    }

    private function createAuthenticatedClientEmpty(): KernelBrowser
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'empty-dash-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Empty Dashboard',
            slug: 'empty-dashboard-'.Uuid::uuid7()->toString(),
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

    #[Test]
    public function dashboardOverviewReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h3', 'Monitored Domains');
    }

    #[Test]
    public function dashboardOverviewShowsStatCards(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app');

        self::assertSelectorExists('.card');
        self::assertSelectorTextContains('body', 'DMARC Pass Rate');
        self::assertSelectorTextContains('body', 'Reports (30 days)');
        self::assertSelectorTextContains('body', 'Total Messages');
    }

    #[Test]
    public function dashboardOverviewUsesDashboardLayout(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app');

        self::assertSelectorExists('aside');
        self::assertSelectorTextContains('aside', 'Dashboard');
        self::assertSelectorTextContains('aside', 'Domains');
        self::assertSelectorTextContains('aside', 'Reports');
        self::assertSelectorTextContains('aside', 'Mailboxes');
    }

    #[Test]
    public function domainsListReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'dashboard-test.com');
    }

    #[Test]
    public function domainsListShowsAddButton(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains');

        self::assertSelectorExists('a[href="/app/domains/add"]');
    }

    #[Test]
    public function domainDetailReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.$data['domainId']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'dashboard-test.com');
    }

    #[Test]
    public function domainDetailShowsChartsAndStats(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.$data['domainId']);

        self::assertSelectorTextContains('body', 'Pass Rate');
        self::assertSelectorTextContains('body', 'Unique Senders');
        self::assertSelectorTextContains('body', 'DMARC Pass/Fail Trend');
        self::assertSelectorTextContains('body', 'Top Senders');
    }

    #[Test]
    public function domainDetailReturns404ForNonexistent(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.Uuid::uuid7());

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function reportsListReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'google.com');
    }

    #[Test]
    public function domainReportsReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/reports');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function reportDetailReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/reports/'.$data['reportId']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Report from google.com');
    }

    #[Test]
    public function reportDetailShowsRecordsTable(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/reports/'.$data['reportId']);

        self::assertSelectorTextContains('body', '1.2.3.4');
        self::assertSelectorTextContains('body', 'Published Policy');
    }

    #[Test]
    public function reportDetailReturns404ForNonexistent(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/reports/'.Uuid::uuid7());

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function addDomainPageReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/domains/add');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Add a domain');
    }

    #[Test]
    public function addDomainFormCreatesDomainAndRedirects(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('POST', '/app/domains/add', [
            'domain_name' => 'new-added.com',
        ]);

        self::assertResponseRedirects();
        $location = $data['client']->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringContainsString('/app/domains/', $location);
    }

    #[Test]
    public function addDomainFormShowsErrorsForInvalidInput(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('POST', '/app/domains/add', [
            'domain_name' => 'not a valid domain',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-error');
    }

    #[Test]
    public function mailboxesListReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function mailboxesListShowsEmptyState(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertSelectorTextContains('body', 'No mailboxes connected');
    }

    #[Test]
    public function addMailboxPageReturns200(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app/mailboxes/add');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Connect a mailbox');
    }

    #[Test]
    public function emptyDomainListShowsEmptyState(): void
    {
        $client = $this->createAuthenticatedClientEmpty();

        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No domains yet');
    }

    #[Test]
    public function emptyReportsListShowsEmptyState(): void
    {
        $client = $this->createAuthenticatedClientEmpty();

        $client->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No reports yet');
    }

    #[Test]
    public function allDashboardPagesUseDashboardLayout(): void
    {
        $data = $this->createAuthenticatedClientWithData();
        $client = $data['client'];

        $pages = [
            '/app',
            '/app/domains',
            '/app/domains/'.$data['domainId'],
            '/app/reports',
            '/app/reports/'.$data['reportId'],
            '/app/domains/add',
            '/app/mailboxes',
            '/app/mailboxes/add',
        ];

        foreach ($pages as $page) {
            $client->request('GET', $page);

            if ($client->getResponse()->isRedirection()) {
                continue;
            }

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('aside', sprintf('Page %s missing sidebar from dashboard layout', $page));
        }
    }
}
