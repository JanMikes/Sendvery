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
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Integration coverage for TASK-040: in-card filters on the `/app` Recent
 * Reports + Domain Health cards.
 *
 * Each seed builds three domains with controlled report dates and pass-rates
 * so each filter branch can be exercised end-to-end through the live
 * controller — no template-only rendering shortcuts.
 */
final class DashboardOverviewFiltersTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, persona: Persona, reporterRecent: string, reporterStale: string, reporterAttention: string, healthyDomainName: string, attentionDomainName: string}
     */
    private function createClientWithMixedReports(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(uniqid('', true), -6);
        $persona = $fixtures->persona()
            ->emailPrefix('overview-filters-'.$suffix)
            ->teamName('Overview Filters '.$suffix)
            ->withDomain('primary-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);

        // Healthy domain — 100% pass.
        $healthy = $fixtures->addExtraDomain($persona->team, 'healthy-'.$suffix);
        $reporterRecent = 'recent-reporter-'.$suffix.'.com';
        $this->persistReport($em, $healthy, '-2 days', pass: 100, fail: 0, reporterOrg: $reporterRecent);

        // Attention domain — 30% pass, two reports → higher report count for
        // Most-sort, and the second report is a 10% pass-rate one needed for
        // the failing-only chip assertion.
        $attention = $fixtures->addExtraDomain($persona->team, 'attention-'.$suffix);
        $reporterAttention = 'attention-reporter-'.$suffix.'.com';
        $this->persistReport($em, $attention, '-2 days', pass: 3, fail: 7, reporterOrg: $reporterAttention);
        $this->persistReport($em, $attention, '-1 day', pass: 1, fail: 9, reporterOrg: $reporterAttention);

        // Old-only report on the primary domain — falls outside 7d default,
        // inside 30d / 90d windows.
        $reporterStale = 'stale-reporter-'.$suffix.'.com';
        $this->persistReport($em, $persona->domain, '-20 days', pass: 5, fail: 5, reporterOrg: $reporterStale);

        $em->flush();
        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'persona' => $persona,
            'reporterRecent' => $reporterRecent,
            'reporterStale' => $reporterStale,
            'reporterAttention' => $reporterAttention,
            'healthyDomainName' => $healthy->domain,
            'attentionDomainName' => $attention->domain,
        ];
    }

    private function persistReport(EntityManagerInterface $em, MonitoredDomain $domain, string $relativeDate, int $pass, int $fail, string $reporterOrg = 'google.com'): void
    {
        $end = new \DateTimeImmutable($relativeDate);
        $begin = $end->modify('-1 hour');
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: $reporterOrg,
            reporterEmail: 'noreply@'.$reporterOrg,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: $begin,
            dateRangeEnd: $end,
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        if ($pass > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '1.2.3.4',
                count: $pass,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $domain->domain,
            ));
        }

        if ($fail > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '5.6.7.8',
                count: $fail,
                disposition: Disposition::None,
                dkimResult: AuthResult::Fail,
                spfResult: AuthResult::Fail,
                headerFrom: $domain->domain,
            ));
        }
    }

    #[Test]
    public function recentReportsDefaultsToSevenDayWindow(): void
    {
        $data = $this->createClientWithMixedReports();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // Within 7d window: recent + attention reporters → stale reporter excluded.
        self::assertStringContainsString($data['reporterRecent'], $body);
        self::assertStringContainsString($data['reporterAttention'], $body);
        self::assertStringNotContainsString($data['reporterStale'], $body);
        // Default-active range label appears in the dropdown trigger.
        self::assertStringContainsString('Last 7 days', $body);
    }

    #[Test]
    public function recentReportsRangeCanBeChangedTo30dAnd90d(): void
    {
        $data = $this->createClientWithMixedReports();

        // 30d window now includes the -20d stale-reporter report.
        $data['client']->request('GET', '/app?recent_reports_range=30d');
        $body30 = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['reporterStale'], $body30);
        self::assertStringContainsString('Last 30 days', $body30);

        $data['client']->request('GET', '/app?recent_reports_range=90d');
        $body90 = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['reporterStale'], $body90);
        self::assertStringContainsString('Last 90 days', $body90);
    }

    #[Test]
    public function recentReportsFailingChipToggleFiltersByLowPassRate(): void
    {
        $data = $this->createClientWithMixedReports();

        $data['client']->request('GET', '/app?recent_reports_failing=1');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // recent-reporter (100% pass) is excluded; attention-reporter (30%/10%) survives.
        self::assertStringNotContainsString($data['reporterRecent'], $body);
        self::assertStringContainsString($data['reporterAttention'], $body);
        // Chip should now render as the active "clear" affordance.
        self::assertStringContainsString('Showing failing only — clear', $body);
    }

    /**
     * Extract the substring of the rendered page that contains only the
     * Domain Health card body — the banner / verification card / next-action
     * surfaces above the grid also mention domain names and would otherwise
     * pollute strpos() ordering assertions.
     */
    private function extractDomainHealthCardBody(KernelBrowser $client): string
    {
        $crawler = $client->getCrawler();
        $card = $crawler->filter('h3:contains("Domain Health")')
            ->ancestors()
            ->filter('div.card')
            ->first();
        self::assertCount(1, $card, 'Domain Health card must render on /app');

        return $card->html();
    }

    #[Test]
    public function domainHealthSortDefaultsToWorstFirst(): void
    {
        $data = $this->createClientWithMixedReports();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $cardBody = $this->extractDomainHealthCardBody($data['client']);
        $body = (string) $data['client']->getResponse()->getContent();
        // Worst-first → attention (20%) precedes healthy (100%) inside the card.
        $attentionPos = strpos($cardBody, $data['attentionDomainName']);
        $healthyPos = strpos($cardBody, $data['healthyDomainName']);
        self::assertNotFalse($attentionPos);
        self::assertNotFalse($healthyPos);
        self::assertLessThan($healthyPos, $attentionPos);
        self::assertStringContainsString('Worst first', $body);
    }

    #[Test]
    public function domainHealthSortBestPutsHighestPassRateFirst(): void
    {
        $data = $this->createClientWithMixedReports();

        $data['client']->request('GET', '/app?domain_health_sort=best');

        self::assertResponseIsSuccessful();
        $cardBody = $this->extractDomainHealthCardBody($data['client']);
        $body = (string) $data['client']->getResponse()->getContent();
        $healthyPos = strpos($cardBody, $data['healthyDomainName']);
        $attentionPos = strpos($cardBody, $data['attentionDomainName']);
        self::assertNotFalse($healthyPos);
        self::assertNotFalse($attentionPos);
        self::assertLessThan($attentionPos, $healthyPos);
        self::assertStringContainsString('Best first', $body);
    }

    #[Test]
    public function domainHealthSortBestPinsZeroRecordDomainsBelowDomainsWithData(): void
    {
        // Regression guard: a domain with zero DMARC records must NOT float to
        // the top of the Best-first list. Without NULLS LAST on the pass-rate
        // expression, PostgreSQL sorts NULLs FIRST under DESC, so a brand-new
        // domain would appear above genuinely-100%-pass-rate domains. The Best
        // sort uses the NULL-aware pass-rate expr + NULLS LAST to pin zero-
        // record domains to the bottom.
        $data = $this->createClientWithMixedReports();
        $em = $data['client']->getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $fixtures = TestFixtures::fromContainer($data['client']->getContainer());
        $emptyDomain = $fixtures->addExtraDomain($data['persona']->team, 'empty-no-reports');
        $em->flush();

        $data['client']->request('GET', '/app?domain_health_sort=best');

        self::assertResponseIsSuccessful();
        $cardBody = $this->extractDomainHealthCardBody($data['client']);

        $healthyPos = strpos($cardBody, $data['healthyDomainName']);
        $emptyPos = strpos($cardBody, $emptyDomain->domain);

        self::assertNotFalse($healthyPos, 'Healthy-with-data domain must render in the Domain Health card.');
        self::assertNotFalse($emptyPos, 'Zero-record domain must render in the Domain Health card.');
        self::assertLessThan(
            $emptyPos,
            $healthyPos,
            'Under Best-first sort, a zero-record domain must NOT outrank a 100%-pass-rate domain — Sort by NULLS LAST.',
        );
    }

    #[Test]
    public function domainHealthSortMostPutsHighestReportCountFirst(): void
    {
        $data = $this->createClientWithMixedReports();

        $data['client']->request('GET', '/app?domain_health_sort=most');

        self::assertResponseIsSuccessful();
        $cardBody = $this->extractDomainHealthCardBody($data['client']);
        $body = (string) $data['client']->getResponse()->getContent();
        // attention has 2 reports vs healthy's 1 → attention listed first.
        $attentionPos = strpos($cardBody, $data['attentionDomainName']);
        $healthyPos = strpos($cardBody, $data['healthyDomainName']);
        self::assertNotFalse($attentionPos);
        self::assertNotFalse($healthyPos);
        self::assertLessThan($healthyPos, $attentionPos);
        self::assertStringContainsString('Most reports', $body);
    }

    #[Test]
    public function invalidRangeOrSortFallsBackToDefault(): void
    {
        $data = $this->createClientWithMixedReports();

        $data['client']->request('GET', '/app?recent_reports_range=garbage&domain_health_sort=garbage');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // Both defaults remain active despite garbage values.
        self::assertStringContainsString('Last 7 days', $body);
        self::assertStringContainsString('Worst first', $body);
    }

    #[Test]
    public function domainPassRateSparklinesRender(): void
    {
        $data = $this->createClientWithMixedReports();

        $crawler = $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // Each top-5 domain row carries a sparkline SVG. The Domain Health
        // card holds three of the seeded domains (primary, healthy, attention).
        self::assertStringContainsString('30-day pass-rate trend', $body);
        // Sparkline SVGs use the dedicated viewBox; polyline tags appear
        // wherever a domain has 2+ buckets of data.
        $svgs = $crawler->filterXPath('//svg[@viewBox="0 0 80 20"]');
        self::assertGreaterThanOrEqual(3, $svgs->count(), 'Expected at least 3 sparkline SVGs (one per top-5 domain)');
        $polylines = $crawler->filterXPath('//svg[@viewBox="0 0 80 20"]/polyline');
        self::assertGreaterThanOrEqual(1, $polylines->count(), 'Expected at least one polyline among the sparklines');
    }
}
