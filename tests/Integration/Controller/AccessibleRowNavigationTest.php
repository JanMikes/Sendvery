<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Regression guard for TASK-018: all dashboard tables that "navigate on row
 * click" must use the stretched-link <a> pattern, not <tr onclick=...>. The
 * onclick pattern silently breaks middle-click ("open in new tab"), keyboard
 * focus, screen-reader link announcement, and right-click "copy link".
 *
 * These tests also lock in the Domains-vs-DNS-Health sidebar split so a
 * future router rename can't quietly re-collide the two highlights.
 */
final class AccessibleRowNavigationTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, domainId: UuidInterface, reportId: UuidInterface}
     */
    private function createAuthenticatedClientWithReport(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('rownav')
            ->teamName('Row Nav Test')
            ->withDomain('rownav-test.example')
            ->build();
        assert(null !== $persona->domain);

        $reportId = Uuid::uuid7();
        $report = new DmarcReport(
            id: $reportId,
            monitoredDomain: $persona->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-rownav-'.Uuid::uuid7()->toString(),
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

    #[Test]
    public function reportListRowHasAnchorNotOnclick(): void
    {
        $data = $this->createAuthenticatedClientWithReport();

        $crawler = $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringNotContainsString('onclick', $body);
        self::assertGreaterThan(
            0,
            $crawler->filter('table tbody tr td a[href*="/app/reports/"]')->count(),
            'Expected at least one stretched-link <a> inside the reports table.',
        );
    }

    #[Test]
    public function domainDetailRowHasAnchorNotOnclick(): void
    {
        $data = $this->createAuthenticatedClientWithReport();

        $crawler = $data['client']->request('GET', '/app/domains/'.$data['domainId']);

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringNotContainsString('onclick', $body);
        self::assertGreaterThan(
            0,
            $crawler->filter('table tbody tr td a[href*="/app/reports/"]')->count(),
            'Expected at least one stretched-link <a> inside the domain detail "Recent Reports" table.',
        );
    }

    #[Test]
    public function overviewRowHasAnchorNotOnclick(): void
    {
        $data = $this->createAuthenticatedClientWithReport();

        $crawler = $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringNotContainsString('onclick', $body);
        self::assertGreaterThan(
            0,
            $crawler->filter('table tbody tr td a[href*="/app/reports/"]')->count(),
            'Expected at least one stretched-link <a> inside the overview "Recent Reports" table.',
        );
    }

    #[Test]
    public function domainReportsListRowHasAnchorNotOnclick(): void
    {
        $data = $this->createAuthenticatedClientWithReport();

        $crawler = $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/reports');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringNotContainsString('onclick', $body);
        self::assertGreaterThan(
            0,
            $crawler->filter('table tbody tr td a[href*="/app/reports/"]')->count(),
            'Expected at least one stretched-link <a> inside the per-domain reports table.',
        );
    }

    #[Test]
    public function reportListRowAnchorHasAriaLabel(): void
    {
        $data = $this->createAuthenticatedClientWithReport();

        $crawler = $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('table tbody tr td a[aria-label]')->count(),
            'Stretched-link anchor must carry aria-label so screen readers announce a link, not a row.',
        );
    }

    #[Test]
    public function reportListRowAnchorHasTurboFrameTop(): void
    {
        // /app/reports is wrapped in <turbo-frame id="reports-table">; the row
        // anchor MUST escape with data-turbo-frame="_top" or the report-detail
        // page will try to render inside the table frame.
        $data = $this->createAuthenticatedClientWithReport();

        $crawler = $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('table tbody tr td a[data-turbo-frame="_top"]')->count(),
            'Row anchor inside <turbo-frame id="reports-table"> must escape with data-turbo-frame="_top".',
        );
    }

    #[Test]
    public function domainReportsListRowAnchorHasTurboFrameTop(): void
    {
        // Same constraint for the per-domain reports table, wrapped in
        // <turbo-frame id="domain-reports-table"> by TASK-016.
        $data = $this->createAuthenticatedClientWithReport();

        $crawler = $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/reports');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('table tbody tr td a[data-turbo-frame="_top"]')->count(),
            'Row anchor inside <turbo-frame id="domain-reports-table"> must escape with data-turbo-frame="_top".',
        );
    }

    #[Test]
    public function noOnclickInAnyDashboardPage(): void
    {
        // Global regression guard — every dashboard page rendered for a known
        // persona must be onclick-free. Future row-handler patterns that
        // re-introduce JS-only navigation will fail this test immediately.
        $data = $this->createAuthenticatedClientWithReport();
        $client = $data['client'];

        $pages = [
            '/app',
            '/app/domains',
            '/app/reports',
            '/app/alerts',
            '/app/mailboxes',
            '/app/settings/billing',
            '/app/settings/preferences',
            '/app/team',
            '/app/domains/'.$data['domainId'],
            '/app/domains/'.$data['domainId'].'/reports',
        ];

        foreach ($pages as $page) {
            $client->request('GET', $page);

            if ($client->getResponse()->isRedirection()) {
                continue;
            }

            self::assertResponseIsSuccessful(sprintf('Page %s did not return 2xx', $page));
            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString(
                'onclick',
                $body,
                sprintf('Page %s reintroduced onclick=… — use stretched-link <a> instead.', $page),
            );
        }
    }

    #[Test]
    public function sidebarDomainsHighlightedOnDomainSubpages(): void
    {
        // The sidebar Domains item uses `current_route starts with 'dashboard_domain'`
        // which legitimately covers dashboard_domain_detail / _health / _reports.
        $data = $this->createAuthenticatedClientWithReport();
        $client = $data['client'];

        $domainSubpages = [
            '/app/domains/'.$data['domainId'],
            '/app/domains/'.$data['domainId'].'/health',
            '/app/domains/'.$data['domainId'].'/reports',
        ];

        foreach ($domainSubpages as $page) {
            $crawler = $client->request('GET', $page);
            self::assertResponseIsSuccessful(sprintf('Page %s did not return 2xx', $page));

            $domainsNav = $crawler->filter('aside nav a:contains("Domains")');
            self::assertGreaterThan(0, $domainsNav->count(), sprintf('Domains nav link missing on %s', $page));
            self::assertStringContainsString(
                'bg-primary',
                (string) $domainsNav->first()->attr('class'),
                sprintf('Domains nav link should be highlighted on %s', $page),
            );
        }
    }
}
