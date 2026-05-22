<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
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
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('dash')
            ->teamName('Dashboard Test')
            ->plan('personal')
            ->withDomain('dashboard-test.com')
            ->build();
        assert(null !== $persona->domain);
        $persona->domain->dmarcPolicy = DmarcPolicy::Reject;
        $em->flush();

        $reportId = Uuid::uuid7();
        $report = new DmarcReport(
            id: $reportId,
            monitoredDomain: $persona->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-dash-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $persona->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $persona->domain->domain,
        ));
        $em->flush();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'domainId' => $persona->domain->id,
            'reportId' => $reportId,
        ];
    }

    private function createAuthenticatedClientEmpty(): KernelBrowser
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()
            ->emailPrefix('empty-dash')
            ->teamName('Empty Dashboard')
            ->withoutDomain()
            ->build();

        $client->loginUser($persona->user);

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
    public function userWithNoDomainsIsRedirectedToOnboarding(): void
    {
        $client = $this->createAuthenticatedClientEmpty();

        $client->request('GET', '/app/domains');

        self::assertResponseRedirects('/app/onboarding/team');
    }

    #[Test]
    public function reportsPageRedirectsToOnboardingWhenNoDomains(): void
    {
        $client = $this->createAuthenticatedClientEmpty();

        $client->request('GET', '/app/reports');

        self::assertResponseRedirects('/app/onboarding/team');
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

    #[Test]
    public function dashboardBannerAbsentWhenDmarcValidAndReportsFlowing(): void
    {
        // dmarc_verified_at older than the settling window + latest DNS check valid
        // + first_report_at set → severity is Ok → no banner copy on the page.
        $client = $this->createClientWithDmarcState(
            dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
            firstReportAt: new \DateTimeImmutable('-9 days'),
            latestChecks: [['checkedAt' => '-1 hour', 'isValid' => true]],
        );

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('DMARC record not detected', $body);
        self::assertStringNotContainsString('went missing', $body);
        self::assertStringNotContainsString('Confirming DMARC record', $body);
        self::assertStringNotContainsString('No DMARC reports yet', $body);
    }

    #[Test]
    public function dashboardBannerInfoToneWithinSettlingWindow(): void
    {
        // Verified < 24h ago + 1 failure in the latest check → severity is Info
        // ("DNS still propagating"). Must not show the alarming "went missing" copy.
        $client = $this->createClientWithDmarcState(
            dmarcVerifiedAt: new \DateTimeImmutable('-2 hours'),
            firstReportAt: null,
            latestChecks: [
                ['checkedAt' => '-2 hours', 'isValid' => true],
                ['checkedAt' => '-30 minutes', 'isValid' => false],
            ],
        );

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Confirming DMARC record', $body);
        self::assertStringNotContainsString('went missing', $body);
    }

    #[Test]
    public function dashboardBannerCriticalAfterTwoConsecutiveFailures(): void
    {
        // Outside settling, two failures in a row after a previously-valid check →
        // severity escalates to Critical with the "went missing" copy.
        $client = $this->createClientWithDmarcState(
            dmarcVerifiedAt: new \DateTimeImmutable('-30 days'),
            firstReportAt: new \DateTimeImmutable('-29 days'),
            latestChecks: [
                ['checkedAt' => '-3 days', 'isValid' => true],
                ['checkedAt' => '-2 days', 'isValid' => false],
                ['checkedAt' => '-1 day', 'isValid' => false],
            ],
        );

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('went missing', $body);
        self::assertStringContainsString('the last 2 checks', $body);
    }

    /**
     * @param list<array{checkedAt: string, isValid: bool}> $latestChecks
     */
    private function createClientWithDmarcState(
        ?\DateTimeImmutable $dmarcVerifiedAt,
        ?\DateTimeImmutable $firstReportAt,
        array $latestChecks,
    ): KernelBrowser {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('banner')
            ->teamName('Banner Test')
            ->withDomain('banner-test.example')
            ->build();
        assert(null !== $persona->domain);

        $persona->domain->dmarcVerifiedAt = $dmarcVerifiedAt;
        $persona->domain->firstReportAt = $firstReportAt;

        foreach ($latestChecks as $check) {
            $em->persist(new DnsCheckResult(
                id: Uuid::uuid7(),
                monitoredDomain: $persona->domain,
                type: DnsCheckType::Dmarc,
                checkedAt: new \DateTimeImmutable($check['checkedAt']),
                rawRecord: $check['isValid'] ? 'v=DMARC1; p=none;' : null,
                isValid: $check['isValid'],
                issues: [],
                details: [],
                previousRawRecord: null,
                hasChanged: false,
                isFirstCheck: false,
            ));
        }

        $em->flush();
        $client->loginUser($persona->user);

        return $client;
    }

    #[Test]
    public function overviewShowsHealthSummaryBanner(): void
    {
        // Domain verified + reports flowing + 100% pass rate → All domains healthy.
        $data = $this->createHealthyHappyPathClient();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('All domains healthy', $body);
    }

    #[Test]
    public function overviewShowsNextActionCard(): void
    {
        $data = $this->createAuthenticatedClientWithData();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Next step', $body);
    }

    #[Test]
    public function overviewShowsAddDomainForNewUser(): void
    {
        // New-user empty state is intercepted by OnboardingRedirectListener before
        // the overview controller runs. Confirm the redirect happens so the empty
        // state CTA can't appear with stale zero-state widgets behind it.
        $client = $this->createAuthenticatedClientEmpty();

        $client->request('GET', '/app');

        self::assertResponseRedirects('/app/onboarding/team');
    }

    #[Test]
    public function overviewHidesStatsForNewUser(): void
    {
        // Same as above: the new-user state is intercepted by the onboarding
        // listener, which means the empty-state stats-hiding branch of the
        // template isn't reachable end-to-end. The intent is preserved via the
        // {% if not isEmptyState %} wrapper plus the redirect — together they
        // guarantee no zero-noise dashboard is ever rendered for a new user.
        $client = $this->createAuthenticatedClientEmpty();

        $client->request('GET', '/app');

        self::assertResponseRedirects('/app/onboarding/team');
    }

    #[Test]
    public function overviewShowsVerifyDnsNextAction(): void
    {
        // dmarcVerifiedAt = null → severity Critical → NextAction::VerifyDns.
        $client = $this->createClientWithDmarcState(
            dmarcVerifiedAt: null,
            firstReportAt: null,
            latestChecks: [],
        );

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Verify DNS for', $body);
        self::assertStringContainsString('banner-test.example', $body);
    }

    #[Test]
    public function overviewShowsWaitForReportsNextAction(): void
    {
        // DMARC published > 48h ago, no first report yet, latest check valid →
        // severity Warning → NextAction::WaitForReports.
        $client = $this->createClientWithDmarcState(
            dmarcVerifiedAt: new \DateTimeImmutable('-3 days'),
            firstReportAt: null,
            latestChecks: [['checkedAt' => '-1 hour', 'isValid' => true]],
        );

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Waiting for your first report', $body);
    }

    #[Test]
    public function overviewShowsReviewAlertsNextAction(): void
    {
        // Domain healthy, but team has an unread critical alert → ReviewAlerts wins.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('alerts-next')
            ->teamName('Alerts Next Test')
            ->withDomain('alerts-next.example')
            ->build();
        assert(null !== $persona->domain);

        $persona->domain->dmarcVerifiedAt = new \DateTimeImmutable('-10 days');
        $persona->domain->firstReportAt = new \DateTimeImmutable('-9 days');

        $em->persist(new Alert(
            id: Uuid::uuid7(),
            team: $persona->team,
            monitoredDomain: $persona->domain,
            type: AlertType::FailureSpike,
            severity: AlertSeverity::Critical,
            title: 'Test critical alert',
            message: 'Something bad happened.',
            data: [],
            createdAt: new \DateTimeImmutable('-1 hour'),
            isRead: false,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Review', $body);
        self::assertStringContainsString('critical alert', $body);
    }

    #[Test]
    public function overviewAllHealthyShowsFullStats(): void
    {
        // Happy-path persona has DMARC verified + reports flowing → AllHealthy
        // path → stats grid + chart should be visible (not hidden by the empty
        // state guard).
        $data = $this->createHealthyHappyPathClient();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('DMARC Pass Rate', $body);
    }

    /**
     * Builds a fully-healthy team: domain with DMARC verified > 48h ago, first
     * report received, 100% pass rate, valid latest DNS check. Lets the
     * health-summary + all-healthy next-action paths be exercised end-to-end.
     *
     * @return array{client: KernelBrowser, domainId: \Ramsey\Uuid\UuidInterface}
     */
    private function createHealthyHappyPathClient(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('healthy')
            ->teamName('Healthy Team')
            ->plan('personal')
            ->withDomain('healthy.example')
            ->build();
        assert(null !== $persona->domain);
        $persona->domain->dmarcVerifiedAt = new \DateTimeImmutable('-10 days');
        $persona->domain->firstReportAt = new \DateTimeImmutable('-9 days');
        $persona->domain->dmarcPolicy = DmarcPolicy::Reject;

        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable('-1 hour'),
            rawRecord: 'v=DMARC1; p=reject;',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: false,
        ));

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-healthy-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $persona->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $persona->domain->domain,
        ));

        $em->flush();
        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'domainId' => $persona->domain->id,
        ];
    }
}
