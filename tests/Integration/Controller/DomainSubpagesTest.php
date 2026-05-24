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
     * the dashboard before this task. Without these the only way to reach them
     * was typing the URL by hand.
     */
    #[Test]
    public function headerHasSendersAndBlacklistButtons(): void
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

        self::assertGreaterThan(
            0,
            $crawler->filter('a.btn.btn-ghost.btn-sm[href="'.$sendersUrl.'"]')->count(),
            'Domain detail header must render a "Senders" ghost button linking to the sender inventory.',
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('a.btn.btn-ghost.btn-sm[href="'.$blacklistUrl.'"]')->count(),
            'Domain detail header must render a "Blacklist" ghost button linking to the blacklist status.',
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
        // `GetDomainSenderBreakdown`. Seed a `KnownSender` for parity with
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
