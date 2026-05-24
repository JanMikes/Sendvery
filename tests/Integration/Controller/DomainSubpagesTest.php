<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DomainHealthSnapshot;
use App\Entity\KnownSender;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Smoke + scenario coverage for the /app/domains/{id}/* subpages that the
 * generic RouteSmokeTest cannot exercise (path parameter required).
 */
final class DomainSubpagesTest extends WebTestCase
{
    /**
     * Subpages that 404 when the domain id is unknown.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function strictDomainSubpathProvider(): iterable
    {
        yield 'blacklist' => ['/app/domains/%s/blacklist'];
        yield 'dns-history' => ['/app/domains/%s/dns-history'];
        yield 'health' => ['/app/domains/%s/health'];
        yield 'reports' => ['/app/domains/%s/reports'];
        yield 'senders' => ['/app/domains/%s/senders'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function allDomainSubpathProvider(): iterable
    {
        yield from self::strictDomainSubpathProvider();
    }

    #[Test]
    #[DataProvider('allDomainSubpathProvider')]
    public function subpageRendersForOwner(string $pathTemplate): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', sprintf($pathTemplate, $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
    }

    #[Test]
    #[DataProvider('strictDomainSubpathProvider')]
    public function subpageReturns404ForUnknownDomain(string $pathTemplate): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        $client->request('GET', sprintf($pathTemplate, Uuid::uuid7()->toString()));

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    #[DataProvider('allDomainSubpathProvider')]
    public function subpageRedirectsAnonymousToLogin(string $pathTemplate): void
    {
        $client = self::createClient();
        $client->request('GET', sprintf($pathTemplate, Uuid::uuid7()->toString()));

        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function pdfExportSucceedsForPaidPlan(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('personal')->build();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', '/app/domains/'.$persona->domain->id.'/export/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
    }

    #[Test]
    public function pdfExportRedirectsForFreePlan(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('free')->build();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', '/app/domains/'.$persona->domain->id.'/export/pdf');

        // Plan gate sends users back to the domain detail page with a flash.
        self::assertResponseRedirects('/app/domains/'.$persona->domain->id);
    }

    #[Test]
    public function pdfExportReturns404ForUnknownDomainOnPaidPlan(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->plan('business')->build();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/domains/'.Uuid::uuid7().'/export/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function reverifyPostRedirectsForOwner(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('POST', '/app/domains/'.$persona->domain->id.'/reverify');

        self::assertResponseRedirects();
    }

    #[Test]
    public function adminCanAccessDomainSubpages(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->role(TeamRole::Admin)->build();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', '/app/domains/'.$persona->domain->id.'/health');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function memberCanAccessDomainSubpages(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()->role(TeamRole::Member)->build();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $client->request('GET', '/app/domains/'.$persona->domain->id.'/blacklist');

        self::assertResponseIsSuccessful();
    }

    /**
     * TASK-031: Senders + Blacklist subsurfaces had ZERO inbound links from
     * the dashboard before TASK-031. TASK-041 removed the duplicated header
     * buttons in favour of the single sibling-tabs strip (which covers all
     * six sub-surfaces, not just two). This test now guards the post-TASK-041
     * invariant: the URLs are still reachable from the detail page via the
     * tab strip, and the legacy "btn btn-ghost btn-sm" header button row
     * does NOT come back (two stacked nav rows on the most-visited
     * authenticated page reads as unfinished).
     */
    #[Test]
    public function siblingTabsLinkToSendersAndBlacklist(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $sendersUrl = '/app/domains/'.$domainId.'/senders';
        $blacklistUrl = '/app/domains/'.$domainId.'/blacklist';

        // Both subsurfaces reachable from the tab strip (role="tab" anchors).
        self::assertGreaterThan(
            0,
            $crawler->filter('a[role="tab"][href="'.$sendersUrl.'"]')->count(),
            'DomainWorkspaceTabs must include a tab to the sender inventory.',
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('a[role="tab"][href="'.$blacklistUrl.'"]')->count(),
            'DomainWorkspaceTabs must include a tab to the blacklist status.',
        );

        // Regression guard: the pre-TASK-041 header button row must NOT
        // come back. If a future change adds a "btn btn-ghost btn-sm" link
        // to these URLs in the header, this test fails.
        self::assertCount(
            0,
            $crawler->filter('a.btn.btn-ghost.btn-sm[href="'.$sendersUrl.'"]'),
            'Legacy header-button row was removed in TASK-041 — sibling tabs are now the sole cross-surface nav.',
        );
        self::assertCount(
            0,
            $crawler->filter('a.btn.btn-ghost.btn-sm[href="'.$blacklistUrl.'"]'),
            'Legacy header-button row was removed in TASK-041 — sibling tabs are now the sole cross-surface nav.',
        );
    }

    #[Test]
    public function uniqueSendersStatCardLinksToSenderInventory(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);

        assert(null !== $persona->domain);
        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $sendersUrl = '/app/domains/'.$domainId.'/senders';
        $wrappingAnchor = $crawler->filter('a.block[href="'.$sendersUrl.'"]');
        self::assertGreaterThan(0, $wrappingAnchor->count(), 'Unique Senders StatCard must be wrapped in a clickable <a class="block"> linking to the sender inventory.');
        self::assertStringContainsString(
            'Unique Senders',
            $wrappingAnchor->text(),
            'The wrapping anchor must contain the "Unique Senders" StatCard text.',
        );
    }

    /**
     * The "View all senders →" link only renders when the senders chart has
     * data — otherwise the template short-circuits into the empty-state branch
     * (where there's nothing to drill into).
     */
    #[Test]
    public function topSendersChartHasViewAllSendersLink(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // The Top Senders chart pulls from `dmarc_record` rows via
        // `GetTopSendersForDomain`. Seed a `KnownSender` for parity with
        // the inventory table AND a real DMARC record so the senders-not-empty
        // branch of the template renders.
        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            sourceIp: '203.0.113.7',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 5000,
            passRate: 98.0,
            hostname: 'mail.example.com',
            organization: 'Example ESP',
            isAuthorized: true,
        ));

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'task-031-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-7 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $persona->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '203.0.113.7',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $persona->domain->domain,
        ));
        $em->flush();

        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $sendersUrl = '/app/domains/'.$domainId.'/senders';
        $link = $crawler->filter('a[href="'.$sendersUrl.'"]')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'View all senders'),
        );
        self::assertGreaterThan(
            0,
            $link->count(),
            'Top Senders chart card must render a "View all senders →" link when sender data is present.',
        );
    }

    /**
     * TASK-038 — the Top Senders card gains a "X authorized · Y unknown ·
     * Z unique IPs" stat row above the chart. Each count clicks through to
     * the matching filter on the sender inventory page.
     */
    #[Test]
    public function topSendersStatRowShowsAuthorizedAndUnknownCounts(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            sourceIp: '203.0.113.7',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 5000,
            passRate: 98.0,
            isAuthorized: true,
        ));
        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            sourceIp: '203.0.113.8',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 200,
            passRate: 10.0,
            isAuthorized: false,
        ));
        $em->flush();

        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $authorizedNode = $crawler->filter('[data-testid="sender-summary-authorized"]');
        $unknownNode = $crawler->filter('[data-testid="sender-summary-unknown"]');
        $uniqueIpsNode = $crawler->filter('[data-testid="sender-summary-unique-ips"]');

        self::assertGreaterThan(0, $authorizedNode->count(), 'Stat row must render the authorized count.');
        self::assertGreaterThan(0, $unknownNode->count(), 'Stat row must render the unknown count.');
        self::assertGreaterThan(0, $uniqueIpsNode->count(), 'Stat row must render the unique-IPs count.');

        self::assertStringContainsString('1', $authorizedNode->text());
        self::assertStringContainsString('authorized', $authorizedNode->text());
        self::assertStringContainsString('1', $unknownNode->text());
        self::assertStringContainsString('unknown', $unknownNode->text());
        self::assertStringContainsString('2', $uniqueIpsNode->text());
        self::assertStringContainsString('unique IPs', $uniqueIpsNode->text());
    }

    #[Test]
    public function topSendersStatRowCountsAreClickable(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            sourceIp: '203.0.113.10',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 5000,
            passRate: 98.0,
            isAuthorized: true,
        ));
        $em->flush();

        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $sendersUrl = '/app/domains/'.$domainId.'/senders';

        $authorized = $crawler->filter('[data-testid="sender-summary-authorized"]')->attr('href');
        $unknown = $crawler->filter('[data-testid="sender-summary-unknown"]')->attr('href');
        $uniqueIps = $crawler->filter('[data-testid="sender-summary-unique-ips"]')->attr('href');

        self::assertSame($sendersUrl.'?filter=authorized', $authorized);
        self::assertSame($sendersUrl.'?filter=unauthorized', $unknown);
        self::assertSame($sendersUrl, $uniqueIps);
    }

    #[Test]
    public function topSendersTableRendersTopFiveByVolume(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'task-038-top5-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-7 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $persona->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        for ($i = 1; $i <= 7; ++$i) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '10.0.0.'.$i,
                count: 100 - $i,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $persona->domain->domain,
                resolvedOrg: 'Org-'.$i,
            ));
        }
        $em->flush();

        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('[data-testid="top-senders-table"] tbody tr');
        self::assertCount(5, $rows, 'Top Senders table must render exactly 5 rows when 7 senders exist.');
    }

    #[Test]
    public function topSendersTableRowsLinkToSenderInventoryWithFragment(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $knownSenderId = Uuid::uuid7();
        $em->persist(new KnownSender(
            id: $knownSenderId,
            monitoredDomain: $persona->domain,
            sourceIp: '203.0.113.7',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 5000,
            passRate: 98.0,
            isAuthorized: true,
        ));

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'task-038-fragment-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-7 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $persona->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '203.0.113.7',
            count: 1000,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $persona->domain->domain,
            resolvedOrg: 'Mailchimp',
        ));
        $em->flush();

        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $sendersUrl = '/app/domains/'.$domainId.'/senders';
        $expected = $sendersUrl.'#sender-'.$knownSenderId->toString();
        $rowLinks = $crawler->filter('[data-testid="top-senders-table"] tbody tr a[href="'.$expected.'"]');
        self::assertGreaterThan(
            0,
            $rowLinks->count(),
            'Each Top Senders table row must deep-link to the sender inventory with `#sender-{id}` fragment.',
        );
    }

    #[Test]
    public function topSendersChartConfigUsesAuthorizationColorTokens(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // One authorized + one unknown IP. The chart's colours array must
        // reference --color-success for the authorized sender and
        // --color-warning for the unknown one (TASK-038 acceptance criterion).
        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            sourceIp: '203.0.113.7',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 5000,
            passRate: 98.0,
            isAuthorized: true,
        ));

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'task-038-colors-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-7 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $persona->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '203.0.113.7',
            count: 1000,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $persona->domain->domain,
            resolvedOrg: 'Authorized Org',
        ));
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '198.51.100.4',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: $persona->domain->domain,
            resolvedOrg: 'Unknown Org',
        ));
        $em->flush();

        $domainId = $persona->domain->id->toString();
        $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('--color-success', $html, 'Chart config must reference --color-success for authorized senders.');
        self::assertStringContainsString('--color-warning', $html, 'Chart config must reference --color-warning for unknown senders.');
    }

    #[Test]
    public function topSendersEmptyStateShowsEducationalCopy(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $emptyState = $crawler->filter('[data-testid="top-senders-empty-state"]');
        self::assertGreaterThan(0, $emptyState->count(), 'Empty state must render when no senders exist.');
        self::assertStringContainsString('DMARC reports tell us which servers', $emptyState->text());
        self::assertGreaterThan(
            0,
            $emptyState->filter('a[href="/learn/what-is-dmarc"]')->count(),
            'Empty state must link to the canonical "What is DMARC" KB article.',
        );
    }

    #[Test]
    public function senderInventoryRowsHaveIdForDeepLinking(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $senderId = Uuid::uuid7();
        $em->persist(new KnownSender(
            id: $senderId,
            monitoredDomain: $persona->domain,
            sourceIp: '203.0.113.7',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 5000,
            passRate: 98.0,
            isAuthorized: true,
        ));
        $em->flush();

        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId.'/senders');

        self::assertResponseIsSuccessful();

        self::assertGreaterThan(
            0,
            $crawler->filter('tr#sender-'.$senderId->toString())->count(),
            'Each sender row must have id="sender-{id}" so the domain-detail top senders table can deep-link via #fragment.',
        );
    }

    #[Test]
    public function blacklistRowLinksToBlacklistStatus(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'B',
            score: 80,
            spfScore: 90,
            dkimScore: 85,
            dmarcScore: 75,
            mxScore: 90,
            blacklistScore: 70,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
        $em->flush();

        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId.'/health');

        self::assertResponseIsSuccessful();

        $blacklistUrl = '/app/domains/'.$domainId.'/blacklist';
        $blacklistRow = $crawler->filter('#health-blacklist a.block[href="'.$blacklistUrl.'"]');
        self::assertGreaterThan(
            0,
            $blacklistRow->count(),
            'The Blacklist row inside the category-scores list must wrap its <progress> in a link to the per-blacklist detail page.',
        );
    }

    #[Test]
    public function dmarcPolicyExplainerRendersOnDomainDetail(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="dmarc-policy-explainer"]')->count(),
            'Domain detail must render the DmarcPolicyExplainer card between quick-stats and charts.',
        );
    }

    #[Test]
    public function dmarcPolicyExplainerShowsCurrentPolicyTitle(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $persona->domain->dmarcPolicy = DmarcPolicy::None;
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString());

        self::assertResponseIsSuccessful();
        $title = $crawler->filter('[data-testid="dmarc-policy-title"]')->text();
        self::assertStringContainsString('p=none', $title);
        self::assertStringContainsString('Monitor-only mode', $title);
    }

    #[Test]
    public function dmarcPolicyExplainerShowsReadyRecommendationWhenEligible(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $persona->domain->dmarcPolicy = DmarcPolicy::None;

        // Seed 3+ reports with all-pass records over the last 30 days so the
        // advisor sees a 100% pass rate AND meets the min-reports threshold.
        for ($i = 0; $i < 4; ++$i) {
            $report = new DmarcReport(
                id: Uuid::uuid7(),
                monitoredDomain: $persona->domain,
                reporterOrg: 'google.com',
                reporterEmail: 'noreply@google.com',
                externalReportId: 'task-037-ready-'.$i.'-'.Uuid::uuid7()->toString(),
                dateRangeBegin: new \DateTimeImmutable('-7 days'),
                dateRangeEnd: new \DateTimeImmutable('-1 day'),
                policyDomain: $persona->domain->domain,
                policyAdkim: DmarcAlignment::Relaxed,
                policyAspf: DmarcAlignment::Relaxed,
                policyP: DmarcPolicy::None,
                policySp: null,
                policyPct: 100,
                rawXml: '<feedback/>',
                processedAt: new \DateTimeImmutable(),
            );
            $em->persist($report);
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '203.0.113.7',
                count: 100,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $persona->domain->domain,
            ));
        }
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString());

        self::assertResponseIsSuccessful();
        $next = $crawler->filter('[data-testid="dmarc-policy-next"]')->text();
        self::assertStringContainsString('p=quarantine', $next);
        self::assertStringContainsString('ready to begin gradual enforcement', $next);
    }

    #[Test]
    public function dmarcPolicyExplainerShowsBuildingDataCopyWhenNotEligible(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $persona->domain->dmarcPolicy = DmarcPolicy::None;

        // Seed enough recent reports to pass the min-reports gate, but with
        // a mix of pass/fail so the trailing pass rate sits below 90%.
        for ($i = 0; $i < 4; ++$i) {
            $report = new DmarcReport(
                id: Uuid::uuid7(),
                monitoredDomain: $persona->domain,
                reporterOrg: 'google.com',
                reporterEmail: 'noreply@google.com',
                externalReportId: 'task-037-notready-'.$i.'-'.Uuid::uuid7()->toString(),
                dateRangeBegin: new \DateTimeImmutable('-7 days'),
                dateRangeEnd: new \DateTimeImmutable('-1 day'),
                policyDomain: $persona->domain->domain,
                policyAdkim: DmarcAlignment::Relaxed,
                policyAspf: DmarcAlignment::Relaxed,
                policyP: DmarcPolicy::None,
                policySp: null,
                policyPct: 100,
                rawXml: '<feedback/>',
                processedAt: new \DateTimeImmutable(),
            );
            $em->persist($report);
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '203.0.113.7',
                count: 100,
                disposition: Disposition::None,
                dkimResult: AuthResult::Fail,
                spfResult: AuthResult::Fail,
                headerFrom: $persona->domain->domain,
            ));
        }
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString());

        self::assertResponseIsSuccessful();
        $next = $crawler->filter('[data-testid="dmarc-policy-next"]')->text();
        self::assertStringContainsString('Still collecting data', $next);
    }

    #[Test]
    public function dmarcPolicyExplainerLinksToMigrationGuide(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString());

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="dmarc-policy-migration-link"]');
        self::assertGreaterThan(0, $link->count(), 'Explainer must render the migration-guide link when not at p=reject.');
        self::assertSame('/learn/dmarc-migration-guide-none-to-reject', $link->attr('href'));
    }

    #[Test]
    public function dmarcPolicyExplainerHidesMigrationLinkAtReject(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $persona->domain->dmarcPolicy = DmarcPolicy::Reject;
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString());

        self::assertResponseIsSuccessful();
        $title = $crawler->filter('[data-testid="dmarc-policy-title"]')->text();
        self::assertStringContainsString('p=reject', $title);
        self::assertStringContainsString('Full enforcement', $title);
        self::assertCount(
            0,
            $crawler->filter('[data-testid="dmarc-policy-migration-link"]'),
            'Migration-guide link must NOT render at p=reject (no next tier).',
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function workspaceTabSurfaceProvider(): iterable
    {
        yield 'overview' => ['/app/domains/%s', 'Overview'];
        yield 'reports' => ['/app/domains/%s/reports', 'Reports'];
        yield 'senders' => ['/app/domains/%s/senders', 'Senders'];
        yield 'dns' => ['/app/domains/%s/health', 'DNS'];
        yield 'blacklist' => ['/app/domains/%s/blacklist', 'Blacklist'];
        yield 'history' => ['/app/domains/%s/dns-history', 'History'];
    }

    #[Test]
    #[DataProvider('workspaceTabSurfaceProvider')]
    public function domainWorkspaceTabsRenderOnEachSurface(string $pathTemplate, string $expectedActiveLabel): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $crawler = $client->request('GET', sprintf($pathTemplate, $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $tablist = $crawler->filter('[role="tablist"]');
        self::assertGreaterThan(0, $tablist->count(), 'Surface must render the DomainWorkspaceTabs role="tablist".');

        $activeTab = $tablist->filter('a.tab.tab-active');
        self::assertGreaterThan(0, $activeTab->count(), 'Exactly one tab anchor must be marked tab-active on every surface.');
        self::assertSame(
            $expectedActiveLabel,
            trim($activeTab->first()->text()),
            'Active tab label must match the surface being rendered.',
        );

        foreach (['Overview', 'Reports', 'Senders', 'DNS', 'Blacklist', 'History'] as $label) {
            self::assertStringContainsString(
                $label,
                $tablist->text(),
                sprintf('Tab row must render the "%s" sibling label on every surface.', $label),
            );
        }
    }
}
