<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components;

use App\Entity\BlacklistCheckResult;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Per-tab attention badges on `<twig:DomainWorkspaceTabs>` (TASK-084).
 *
 * Two scenarios per acceptance:
 *  - "noisy" domain: 2 reports in the last 24h, 3 unauthorized senders,
 *    all-failing DNS snapshot, 1 IP listed on a DNSBL, AND a DNS change in
 *    the last 7 days. Asserts the right count / dot glyph per tab.
 *  - "clean" domain: nothing waiting. Asserts NO badge spans appear in the
 *    tab strip at all.
 *
 * Rendering through the real controller (not isolated component fixtures)
 * keeps the SQL contract honest — a regression in the per-metric subquery
 * column names or join order surfaces here, not in a unit test.
 */
final class DomainWorkspaceTabsCountBadgesTest extends WebTestCase
{
    #[Test]
    public function noisyDomainRendersCountAndDotBadgesPerTab(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = $this->getService(EntityManagerInterface::class);

        // 2 reports within the last 24h (processed_at drives the window).
        $this->persistReport($em, $persona->domain, '-2 hours');
        $this->persistReport($em, $persona->domain, '-20 hours');
        // 1 stale report well outside the 24h window — must NOT inflate the badge.
        $this->persistReport($em, $persona->domain, '-3 days');

        // 3 unauthorized senders + 1 authorized (only unauthorized counted).
        $this->persistKnownSender($em, $persona->domain, '203.0.113.10', isAuthorized: false);
        $this->persistKnownSender($em, $persona->domain, '203.0.113.11', isAuthorized: false);
        $this->persistKnownSender($em, $persona->domain, '203.0.113.12', isAuthorized: false);
        $this->persistKnownSender($em, $persona->domain, '203.0.113.99', isAuthorized: true);

        // All-failing DNS snapshot — every score < 80 — triggers the DNS dot.
        $this->persistHealthSnapshot($em, $persona->domain, spf: 10, dkim: 20, dmarc: 30, mx: 40);

        // 1 IP listed on a DNSBL (latest check per IP must be `is_listed = true`).
        $this->persistBlacklistCheck($em, $persona->domain, '198.51.100.1', isListed: true);
        // Distractor: a delisted IP — the DISTINCT-ON-latest filter must drop this.
        $this->persistBlacklistCheck($em, $persona->domain, '198.51.100.2', isListed: false);

        // 1 DNS change in the last 7 days — triggers the History dot.
        $this->persistDnsCheck($em, $persona->domain, hasChanged: true, checkedAt: '-2 days');

        $em->flush();
        $em->clear();

        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $tablist = $crawler->filter('[role="tablist"]');
        self::assertGreaterThan(0, $tablist->count(), 'DomainWorkspaceTabs must render the role="tablist".');

        $reportsTab = $this->tabAnchor($tablist, '/app/domains/'.$domainId.'/reports');
        self::assertStringContainsString('2', $reportsTab->filter('span.badge')->text(), 'Reports tab must carry a "2" badge.');

        $sendersTab = $this->tabAnchor($tablist, '/app/domains/'.$domainId.'/senders');
        self::assertStringContainsString('3', $sendersTab->filter('span.badge')->text(), 'Senders tab must carry a "3" badge.');

        $dnsTab = $this->tabAnchor($tablist, '/app/domains/'.$domainId.'/health');
        $dnsBadges = $dnsTab->filter('span.badge');
        self::assertCount(1, $dnsBadges, 'DNS tab must carry exactly one (dot) badge when the latest snapshot has a failing protocol.');
        self::assertSame('', trim($dnsBadges->text()), 'DNS badge must be a dot (no number) — "1 failing check" carries no extra information beyond presence.');
        self::assertStringContainsString('w-2', $dnsBadges->attr('class') ?? '', 'DNS dot badge must use w-2 sizing.');

        $blacklistTab = $this->tabAnchor($tablist, '/app/domains/'.$domainId.'/blacklist');
        self::assertStringContainsString('1', $blacklistTab->filter('span.badge')->text(), 'Blacklist tab must carry a "1" badge.');

        $historyTab = $this->tabAnchor($tablist, '/app/domains/'.$domainId.'/dns-history');
        $historyBadges = $historyTab->filter('span.badge');
        self::assertCount(1, $historyBadges, 'History tab must carry exactly one (dot) badge when a DNS record changed in the last 7 days.');
        self::assertSame('', trim($historyBadges->text()), 'History badge must be a dot (no number).');

        $overviewTab = $this->tabAnchor($tablist, '/app/domains/'.$domainId);
        self::assertCount(0, $overviewTab->filter('span.badge'), 'Overview tab is the catch-all and must never carry a badge.');
    }

    /**
     * TASK-115: when the DNS tab is itself active, the warning-amber dot is
     * almost invisible against the dark `tab-active` background unless it
     * carries an additional contrast affordance. Option A from the spec:
     * `ring-1 ring-base-100` punches the dot out of either background.
     *
     * The pair (active-with-ring vs inactive-without-ring) is asserted with
     * the same fixture to lock the symmetry: a regression that drops the
     * ring under active OR leaks it under inactive breaks one of the two.
     */
    #[Test]
    public function activeDnsTabAddsContrastRingToDotBadge(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = $this->getService(EntityManagerInterface::class);
        // All-failing DNS snapshot — guarantees the dot is rendered.
        $this->persistHealthSnapshot($em, $persona->domain, spf: 10, dkim: 20, dmarc: 30, mx: 40);
        $em->flush();
        $em->clear();

        $domainId = $persona->domain->id->toString();

        // Active DNS tab → ring punch-out present, dot still rendered.
        $crawler = $client->request('GET', '/app/domains/'.$domainId.'/health');
        self::assertResponseIsSuccessful();
        $tablist = $crawler->filter('[role="tablist"]');
        $dnsTab = $this->tabAnchor($tablist, '/app/domains/'.$domainId.'/health');
        self::assertStringContainsString('tab-active', $dnsTab->attr('class') ?? '', 'Sanity: DNS tab must carry tab-active on its own route.');
        $dnsBadges = $dnsTab->filter('span.badge');
        self::assertCount(1, $dnsBadges, 'Active DNS tab must still render the dot — the ring is additive, not a replacement.');
        $dnsBadgeClasses = $dnsBadges->attr('class') ?? '';
        self::assertStringContainsString('ring-1', $dnsBadgeClasses, 'Active DNS dot badge must carry ring-1 to punch out of the dark tab-active background.');
        self::assertStringContainsString('ring-base-100', $dnsBadgeClasses, 'Active DNS dot ring must use ring-base-100 (the punch-out tone from TASK-115 option A).');

        // Inactive DNS tab (rendered via Overview) → no ring on the dot.
        $crawler = $client->request('GET', '/app/domains/'.$domainId);
        self::assertResponseIsSuccessful();
        $tablist = $crawler->filter('[role="tablist"]');
        $dnsTab = $this->tabAnchor($tablist, '/app/domains/'.$domainId.'/health');
        self::assertStringNotContainsString('tab-active', $dnsTab->attr('class') ?? '', 'Sanity: DNS tab must not be active on the overview route.');
        $dnsBadges = $dnsTab->filter('span.badge');
        self::assertCount(1, $dnsBadges, 'Inactive DNS tab still renders the dot — only the ring is conditional.');
        self::assertStringNotContainsString('ring-1', $dnsBadges->attr('class') ?? '', 'Inactive DNS dot badge must NOT carry the ring — amber on light tab reads fine without the punch-out.');
    }

    #[Test]
    public function activeHistoryTabAddsContrastRingToDotBadge(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = $this->getService(EntityManagerInterface::class);
        // 1 DNS change in the last 7 days — guarantees the History dot fires.
        $this->persistDnsCheck($em, $persona->domain, hasChanged: true, checkedAt: '-2 days');
        $em->flush();
        $em->clear();

        $domainId = $persona->domain->id->toString();

        // Active History tab → ring punch-out present.
        $crawler = $client->request('GET', '/app/domains/'.$domainId.'/dns-history');
        self::assertResponseIsSuccessful();
        $tablist = $crawler->filter('[role="tablist"]');
        $historyTab = $this->tabAnchor($tablist, '/app/domains/'.$domainId.'/dns-history');
        self::assertStringContainsString('tab-active', $historyTab->attr('class') ?? '', 'Sanity: History tab must carry tab-active on its own route.');
        $historyBadges = $historyTab->filter('span.badge');
        self::assertCount(1, $historyBadges, 'Active History tab must still render the dot — the ring is additive.');
        $historyBadgeClasses = $historyBadges->attr('class') ?? '';
        self::assertStringContainsString('ring-1', $historyBadgeClasses, 'Active History dot badge must carry ring-1 (TASK-115 option A).');
        self::assertStringContainsString('ring-base-100', $historyBadgeClasses, 'Active History dot ring must use ring-base-100.');

        // Inactive History tab (rendered via Overview) → no ring on the dot.
        $crawler = $client->request('GET', '/app/domains/'.$domainId);
        self::assertResponseIsSuccessful();
        $tablist = $crawler->filter('[role="tablist"]');
        $historyTab = $this->tabAnchor($tablist, '/app/domains/'.$domainId.'/dns-history');
        self::assertStringNotContainsString('tab-active', $historyTab->attr('class') ?? '', 'Sanity: History tab must not be active on the overview route.');
        $historyBadges = $historyTab->filter('span.badge');
        self::assertCount(1, $historyBadges, 'Inactive History tab still renders the dot — only the ring is conditional.');
        self::assertStringNotContainsString('ring-1', $historyBadges->attr('class') ?? '', 'Inactive History dot badge must NOT carry the ring.');
    }

    #[Test]
    public function cleanDomainRendersNoBadges(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $em = $this->getService(EntityManagerInterface::class);

        // All-passing snapshot — no DNS dot.
        $this->persistHealthSnapshot($em, $persona->domain, spf: 95, dkim: 95, dmarc: 95, mx: 95);

        // A delisted IP — no Blacklist count (DISTINCT-ON-latest drops it).
        $this->persistBlacklistCheck($em, $persona->domain, '198.51.100.20', isListed: false);

        // An authorized sender — does NOT count toward the "unauthorized" tab.
        $this->persistKnownSender($em, $persona->domain, '203.0.113.50', isAuthorized: true);

        // A stale DNS change well outside the 7-day window — no History dot.
        $this->persistDnsCheck($em, $persona->domain, hasChanged: true, checkedAt: '-30 days');

        // An old report well outside the 24h window — no Reports count.
        $this->persistReport($em, $persona->domain, '-5 days');

        $em->flush();
        $em->clear();

        $domainId = $persona->domain->id->toString();
        $crawler = $client->request('GET', '/app/domains/'.$domainId);

        self::assertResponseIsSuccessful();

        $tablist = $crawler->filter('[role="tablist"]');
        self::assertGreaterThan(0, $tablist->count(), 'DomainWorkspaceTabs must render the role="tablist".');

        self::assertCount(
            0,
            $tablist->filter('span.badge'),
            'A fully-clean domain must render ZERO badges across all six tabs.',
        );
    }

    /**
     * The tabs are anchors keyed by `role="tab"`. `dashboard_domain_detail`
     * (overview) shares its href prefix with every other surface, so we filter
     * by exact href to disambiguate.
     */
    private function tabAnchor(Crawler $tablist, string $href): Crawler
    {
        $anchor = $tablist->filter('a[role="tab"][href="'.$href.'"]');
        self::assertGreaterThan(0, $anchor->count(), sprintf('Tab anchor for href="%s" must exist.', $href));

        return $anchor;
    }

    private function persistReport(EntityManagerInterface $em, MonitoredDomain $domain, string $processedAtRelative): void
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'task-084-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable($processedAtRelative.' -1 hour'),
            dateRangeEnd: new \DateTimeImmutable($processedAtRelative),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable($processedAtRelative),
        );
        $report->popEvents();
        $em->persist($report);
    }

    private function persistKnownSender(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        string $sourceIp,
        bool $isAuthorized,
    ): void {
        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: $sourceIp,
            firstSeenAt: new \DateTimeImmutable('-7 days'),
            lastSeenAt: new \DateTimeImmutable('-1 hour'),
            totalMessages: 50,
            passRate: 100.0,
            isAuthorized: $isAuthorized,
        ));
    }

    private function persistHealthSnapshot(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        int $spf,
        int $dkim,
        int $dmarc,
        int $mx,
    ): void {
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            grade: 'C',
            score: (int) (($spf + $dkim + $dmarc + $mx) / 4),
            spfScore: $spf,
            dkimScore: $dkim,
            dmarcScore: $dmarc,
            mxScore: $mx,
            blacklistScore: 100,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: null,
        ));
    }

    private function persistBlacklistCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        string $ipAddress,
        bool $isListed,
    ): void {
        $em->persist(new BlacklistCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            ipAddress: $ipAddress,
            checkedAt: new \DateTimeImmutable(),
            results: ['zen.spamhaus.org' => ['listed' => $isListed, 'reason' => null]],
            isListed: $isListed,
        ));
    }

    private function persistDnsCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        bool $hasChanged,
        string $checkedAt,
    ): void {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Spf,
            checkedAt: new \DateTimeImmutable($checkedAt),
            rawRecord: 'v=spf1 -all',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: $hasChanged ? 'v=spf1 +all' : null,
            hasChanged: $hasChanged,
        );
        $check->popEvents();
        $em->persist($check);
    }
}
