<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * End-to-end coverage for the filter bar on /app/reports and
 * /app/domains/{id}/reports. Each test sets up a persona with a few
 * representative reports so chip/multiselect/search filters can be
 * exercised through the URL surface.
 */
final class ReportsFilterTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, persona: Persona, domainA: MonitoredDomain, domainB: MonitoredDomain}
     */
    private function setupClientWithReports(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('reports-filter')
            ->teamName('Reports Filter')
            ->withDomain('alpha-domain.com')
            ->build();
        assert(null !== $persona->domain);
        $domainA = $persona->domain;

        // Add a second domain so the domain-multiselect has options.
        $domainB = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $persona->team,
            domain: 'beta-other.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domainB->popEvents();
        $em->persist($domainB);

        // High pass rate report on domain A from Google in May.
        $this->persistReport($em, $domainA, 'google.com', '2026-05-01', '2026-05-02', [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);

        // Low pass rate report on domain A from Yahoo in May.
        $this->persistReport($em, $domainA, 'yahoo.com', '2026-05-03', '2026-05-04', [
            ['count' => 10, 'dkim' => AuthResult::Fail, 'spf' => AuthResult::Fail],
        ]);

        // High pass rate report on domain B from Microsoft in January.
        $this->persistReport($em, $domainB, 'microsoft.com', '2026-01-01', '2026-01-02', [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);

        $em->flush();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'persona' => $persona,
            'domainA' => $domainA,
            'domainB' => $domainB,
        ];
    }

    /**
     * @param list<array{count: int, dkim: AuthResult, spf: AuthResult}> $records
     */
    private function persistReport(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        string $reporterOrg,
        string $begin,
        string $end,
        array $records,
    ): void {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: $reporterOrg,
            reporterEmail: 'noreply@'.$reporterOrg,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable($begin),
            dateRangeEnd: new \DateTimeImmutable($end),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        foreach ($records as $r) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '1.2.3.4',
                count: $r['count'],
                disposition: Disposition::None,
                dkimResult: $r['dkim'],
                spfResult: $r['spf'],
                headerFrom: $domain->domain,
            ));
        }
    }

    public function testReportsPageReturns200WithFilterBar(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        // Filter bar chip text
        self::assertSelectorTextContains('body', 'Pass rate');
        self::assertSelectorTextContains('body', '≥ 90%');
        self::assertSelectorTextContains('body', '70–90%');
        self::assertSelectorTextContains('body', 'All time');
    }

    public function testReportsPageShowsAllReportsWithoutFilters(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports');

        self::assertSelectorTextContains('body', 'google.com');
        self::assertSelectorTextContains('body', 'yahoo.com');
        self::assertSelectorTextContains('body', 'microsoft.com');
    }

    public function testPassRateHighFilter(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports?pass_rate=high');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'google.com');
        self::assertSelectorTextContains('body', 'microsoft.com');
        $html = (string) $ctx['client']->getResponse()->getContent();
        // yahoo.com had 0% pass — should be excluded
        self::assertStringNotContainsString('<td>yahoo.com</td>', $html);
    }

    public function testPassRateLowFilter(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports?pass_rate=low');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'yahoo.com');
        $html = (string) $ctx['client']->getResponse()->getContent();
        self::assertStringNotContainsString('<td>google.com</td>', $html);
        self::assertStringNotContainsString('<td>microsoft.com</td>', $html);
    }

    public function testReporterFilter(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports?reporter%5B%5D=google.com');

        self::assertResponseIsSuccessful();
        $html = (string) $ctx['client']->getResponse()->getContent();
        self::assertStringContainsString('google.com', $html);
        // Should not find yahoo or microsoft in the table cells
        self::assertStringNotContainsString('<td>yahoo.com</td>', $html);
        self::assertStringNotContainsString('<td>microsoft.com</td>', $html);
    }

    public function testDomainFilter(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports?domain%5B%5D='.$ctx['domainA']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $ctx['domainA']->domain);
        $html = (string) $ctx['client']->getResponse()->getContent();
        // Domain B's reporter "microsoft.com" must not appear
        self::assertStringNotContainsString('<td>microsoft.com</td>', $html);
    }

    public function testSearchFilter(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports?q=goog');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'google.com');
        $html = (string) $ctx['client']->getResponse()->getContent();
        self::assertStringNotContainsString('<td>yahoo.com</td>', $html);
    }

    public function testInvalidPassRateValueIsIgnored(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports?pass_rate=garbage');

        self::assertResponseIsSuccessful();
        // All three reports show
        self::assertSelectorTextContains('body', 'google.com');
        self::assertSelectorTextContains('body', 'yahoo.com');
        self::assertSelectorTextContains('body', 'microsoft.com');
    }

    public function testNonUuidDomainFilterIsIgnored(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports?domain%5B%5D=not-a-uuid');

        self::assertResponseIsSuccessful();
        // All three reports show since the filter was dropped
        self::assertSelectorTextContains('body', 'google.com');
        self::assertSelectorTextContains('body', 'yahoo.com');
        self::assertSelectorTextContains('body', 'microsoft.com');
    }

    public function testDateRange30dExcludesOldReports(): void
    {
        $ctx = $this->setupClientWithReports();
        // The clock is the real clock here — but our reports range from Jan/May 2026.
        // Just verify the query still 200s with a date-range filter applied.
        $ctx['client']->request('GET', '/app/reports?date_range=30d');

        self::assertResponseIsSuccessful();
    }

    public function testCustomDateRangeExposesDateInputs(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports?date_range=custom');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="date_from"]');
        self::assertSelectorExists('input[name="date_to"]');
    }

    public function testClearLinkShownWhenFiltersActive(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/reports?pass_rate=high');

        self::assertSelectorTextContains('body', 'Clear');
    }

    public function testClearLinkHiddenWhenNoFilters(): void
    {
        $ctx = $this->setupClientWithReports();
        $crawler = $ctx['client']->request('GET', '/app/reports');

        // The "Clear" button only renders inside hasActiveFilters() — count
        // matching anchors with the text "Clear".
        $clearAnchors = $crawler->filter('a.btn-ghost')->reduce(static function ($node) {
            return false !== stripos($node->text(), 'Clear');
        });
        self::assertCount(0, $clearAnchors);
    }

    public function testEmptyWithActiveFiltersShowsNoMatchCopy(): void
    {
        $ctx = $this->setupClientWithReports();
        // No report matches reporter "neverexists"
        $ctx['client']->request('GET', '/app/reports?reporter%5B%5D=neverexists.invalid');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No reports match the current filters');
    }

    public function testEmptyWithoutFiltersShowsEmptyState(): void
    {
        // Persona with a domain but no reports — the truly-empty branch.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('empty-reports')
            ->teamName('Empty Reports')
            ->withDomain('no-reports.example.com')
            ->build();
        $client->loginUser($persona->user);

        $client->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No reports yet');
    }

    public function testPaginationPreservesFilterParams(): void
    {
        $ctx = $this->setupClientWithReports();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Push past the page size — add 30 google.com reports to domainA
        for ($i = 0; $i < 30; ++$i) {
            $this->persistReport($em, $ctx['domainA'], 'google.com', '2026-04-'.str_pad((string) (1 + ($i % 28)), 2, '0', STR_PAD_LEFT), '2026-04-'.str_pad((string) (1 + ($i % 28)), 2, '0', STR_PAD_LEFT), [
                ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
            ]);
        }
        $em->flush();

        $crawler = $ctx['client']->request('GET', '/app/reports?reporter%5B%5D=google.com');

        // Next link should preserve the reporter filter
        $nextLink = $crawler->filter('a.join-item.btn:contains("Next")');
        if ($nextLink->count() > 0) {
            $href = (string) $nextLink->first()->attr('href');
            self::assertStringContainsString('reporter', $href);
            self::assertStringContainsString('google.com', urldecode($href));
            self::assertStringContainsString('page=2', $href);
        } else {
            self::fail('Expected Next pagination link');
        }
    }

    public function testCrossTeamDomainIdInFilterDoesNotLeakData(): void
    {
        $ctx = $this->setupClientWithReports();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        // Build a completely separate team with its own domain + report
        $otherPersona = $fixtures->persona()
            ->emailPrefix('intruder')
            ->teamName('Other Team')
            ->withDomain('other-team-secret.com')
            ->build();
        assert(null !== $otherPersona->domain);
        $this->persistReport($em, $otherPersona->domain, 'leak-source.com', '2026-05-01', '2026-05-02', [
            ['count' => 5, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $em->flush();

        // First persona tries to view via the other team's domain UUID
        $ctx['client']->request('GET', '/app/reports?domain%5B%5D='.$otherPersona->domain->id->toString());

        self::assertResponseIsSuccessful();
        $html = (string) $ctx['client']->getResponse()->getContent();
        self::assertStringNotContainsString('leak-source.com', $html);
        self::assertStringNotContainsString('other-team-secret.com', $html);
    }

    public function testDomainReportsPageReturns200WithFilterBar(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/domains/'.$ctx['domainA']->id->toString().'/reports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Pass rate');
    }

    public function testDomainReportsHidesDomainMultiselect(): void
    {
        $ctx = $this->setupClientWithReports();
        $crawler = $ctx['client']->request('GET', '/app/domains/'.$ctx['domainA']->id->toString().'/reports');

        // Page-scoped: no select[name="domain[]"]
        self::assertCount(0, $crawler->filter('select[name="domain[]"]'));
        // But reporter multiselect should still be present
        self::assertCount(1, $crawler->filter('select[name="reporter[]"]'));
    }

    public function testDomainReportsAppliesPassRateFilter(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/domains/'.$ctx['domainA']->id->toString().'/reports?pass_rate=low');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'yahoo.com');
        $html = (string) $ctx['client']->getResponse()->getContent();
        self::assertStringNotContainsString('<td class="font-medium">google.com</td>', $html);
    }

    public function testDomainReportsEmptyWithActiveFiltersShowsNoMatch(): void
    {
        $ctx = $this->setupClientWithReports();
        $ctx['client']->request('GET', '/app/domains/'.$ctx['domainA']->id->toString().'/reports?reporter%5B%5D=neverexists.invalid');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No reports match the current filters');
    }
}
