<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class DashboardPagesTest extends WebTestCase
{
    private function createTeamWithData(): array
    {
        $em = $this->getService(EntityManagerInterface::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Dashboard Test',
            slug: 'dashboard-test-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable('2000-01-01'),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'dashboard-test.com',
            createdAt: new \DateTimeImmutable(),
            dmarcPolicy: DmarcPolicy::Reject,
        );
        $em->persist($domain);

        $reportId = Uuid::uuid7();
        $report = new DmarcReport(
            id: $reportId,
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-dash-' . Uuid::uuid7()->toString(),
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

        return [
            'teamId' => $teamId,
            'domainId' => $domainId,
            'reportId' => $reportId,
        ];
    }

    #[Test]
    public function dashboard_overview_returns_200(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h3', 'Monitored Domains');
    }

    #[Test]
    public function dashboard_overview_shows_stat_cards(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $crawler = $client->request('GET', '/app');

        self::assertSelectorExists('.card');
        self::assertSelectorTextContains('body', 'DMARC Pass Rate');
        self::assertSelectorTextContains('body', 'Reports (30 days)');
        self::assertSelectorTextContains('body', 'Total Messages');
    }

    #[Test]
    public function dashboard_overview_uses_dashboard_layout(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $crawler = $client->request('GET', '/app');

        // Dashboard layout has sidebar with navigation
        self::assertSelectorExists('aside');
        self::assertSelectorTextContains('aside', 'Dashboard');
        self::assertSelectorTextContains('aside', 'Domains');
        self::assertSelectorTextContains('aside', 'Reports');
        self::assertSelectorTextContains('aside', 'Mailboxes');
    }

    #[Test]
    public function domains_list_returns_200(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'dashboard-test.com');
    }

    #[Test]
    public function domains_list_shows_add_button(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $crawler = $client->request('GET', '/app/domains');

        self::assertSelectorExists('a[href="/app/domains/add"]');
    }

    #[Test]
    public function domain_detail_returns_200(): void
    {
        $client = self::createClient();
        $data = $this->createTeamWithData();

        $client->request('GET', '/app/domains/' . $data['domainId']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'dashboard-test.com');
    }

    #[Test]
    public function domain_detail_shows_charts_and_stats(): void
    {
        $client = self::createClient();
        $data = $this->createTeamWithData();

        $crawler = $client->request('GET', '/app/domains/' . $data['domainId']);

        self::assertSelectorTextContains('body', 'Pass Rate');
        self::assertSelectorTextContains('body', 'Unique Senders');
        self::assertSelectorTextContains('body', 'DMARC Pass/Fail Trend');
        self::assertSelectorTextContains('body', 'Top Senders');
    }

    #[Test]
    public function domain_detail_returns_404_for_nonexistent(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $client->request('GET', '/app/domains/' . Uuid::uuid7());

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function reports_list_returns_200(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $client->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'google.com');
    }

    #[Test]
    public function domain_reports_returns_200(): void
    {
        $client = self::createClient();
        $data = $this->createTeamWithData();

        $client->request('GET', '/app/domains/' . $data['domainId'] . '/reports');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function report_detail_returns_200(): void
    {
        $client = self::createClient();
        $data = $this->createTeamWithData();

        $client->request('GET', '/app/reports/' . $data['reportId']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Report from google.com');
    }

    #[Test]
    public function report_detail_shows_records_table(): void
    {
        $client = self::createClient();
        $data = $this->createTeamWithData();

        $crawler = $client->request('GET', '/app/reports/' . $data['reportId']);

        self::assertSelectorTextContains('body', '1.2.3.4');
        self::assertSelectorTextContains('body', 'Published Policy');
    }

    #[Test]
    public function report_detail_returns_404_for_nonexistent(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $client->request('GET', '/app/reports/' . Uuid::uuid7());

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function add_domain_page_returns_200(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $client->request('GET', '/app/domains/add');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Add a domain');
    }

    #[Test]
    public function add_domain_form_creates_domain_and_redirects(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $client->request('POST', '/app/domains/add', [
            'domain_name' => 'new-added.com',
        ]);

        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/app/domains/', $location);
    }

    #[Test]
    public function add_domain_form_shows_errors_for_invalid_input(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $client->request('POST', '/app/domains/add', [
            'domain_name' => 'not a valid domain',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-error');
    }

    #[Test]
    public function mailboxes_list_returns_200(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $client->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function mailboxes_list_shows_empty_state(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $crawler = $client->request('GET', '/app/mailboxes');

        self::assertSelectorTextContains('body', 'No mailboxes connected');
    }

    #[Test]
    public function add_mailbox_page_returns_200(): void
    {
        $client = self::createClient();
        $this->createTeamWithData();

        $client->request('GET', '/app/mailboxes/add');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Connect a mailbox');
    }

    #[Test]
    public function empty_domain_list_shows_empty_state(): void
    {
        $client = self::createClient();
        $em = $this->getService(EntityManagerInterface::class);

        // Create team with no domains
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Empty Dashboard',
            slug: 'empty-dashboard-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $client->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No domains yet');
    }

    #[Test]
    public function empty_reports_list_shows_empty_state(): void
    {
        $client = self::createClient();
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Empty Reports',
            slug: 'empty-reports-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $client->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No reports yet');
    }

    #[Test]
    public function all_dashboard_pages_use_dashboard_layout(): void
    {
        $client = self::createClient();
        $data = $this->createTeamWithData();

        $pages = [
            '/app',
            '/app/domains',
            '/app/domains/' . $data['domainId'],
            '/app/reports',
            '/app/reports/' . $data['reportId'],
            '/app/domains/add',
            '/app/mailboxes',
            '/app/mailboxes/add',
        ];

        foreach ($pages as $page) {
            $crawler = $client->request('GET', $page);

            if ($client->getResponse()->isRedirection()) {
                continue;
            }

            self::assertResponseIsSuccessful();
            // Dashboard layout always has sidebar
            self::assertSelectorExists('aside', sprintf('Page %s missing sidebar from dashboard layout', $page));
        }
    }
}
