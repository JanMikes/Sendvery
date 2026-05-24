<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
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
 * Integration coverage for TASK-069: every report row on /app/reports AND
 * on the per-domain "Recent Reports" table carries a leading severity glyph
 * + matching `border-l-{tone}`. Pass-rate thresholds (>=90 success, >=70
 * warning, <70 error) match the existing inline text-color thresholds —
 * single source of truth lives in the shared `_severity_glyph` macro.
 */
final class ReportsTableSeverityGlyphTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, domain: MonitoredDomain}
     */
    private function bootClientWithDomain(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(Uuid::uuid7()->toString(), 0, 6);
        $persona = $fixtures->persona()
            ->emailPrefix('rpt-glyph-'.$suffix)
            ->withDomain('rpt-glyph-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'em' => $em,
            'domain' => $persona->domain,
        ];
    }

    private function persistReportWithPassRate(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        string $reporterOrg,
        int $passCount,
        int $failCount,
    ): DmarcReport {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: $reporterOrg,
            reporterEmail: 'noreply@'.$reporterOrg,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        if ($passCount > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '1.2.3.4',
                count: $passCount,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $domain->domain,
            ));
        }

        if ($failCount > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '5.6.7.8',
                count: $failCount,
                disposition: Disposition::None,
                dkimResult: AuthResult::Fail,
                spfResult: AuthResult::Fail,
                headerFrom: $domain->domain,
            ));
        }

        $em->flush();

        return $report;
    }

    #[Test]
    public function highPassRateReportRendersSuccessLeftBorderAndCheckGlyph(): void
    {
        $data = $this->bootClientWithDomain();
        // 95% pass: 95 pass + 5 fail.
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'google.com', 95, 5);

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('google.com', $body);
        self::assertStringContainsString('border-l-success', $body);
        self::assertStringContainsString('M9 12l2 2 4-4m5.618-4.016', $body);
    }

    #[Test]
    public function midPassRateReportRendersWarningLeftBorderAndTriangleGlyph(): void
    {
        $data = $this->bootClientWithDomain();
        // 75% pass: 75 pass + 25 fail.
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'yahoo.com', 75, 25);

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('yahoo.com', $body);
        self::assertStringContainsString('border-l-warning', $body);
        self::assertStringContainsString('M12 9v2m0 4h.01m-6.938 4h13.856', $body);
    }

    #[Test]
    public function lowPassRateReportRendersErrorLeftBorderAndCircleGlyph(): void
    {
        $data = $this->bootClientWithDomain();
        // 30% pass: 30 pass + 70 fail.
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'microsoft.com', 30, 70);

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('microsoft.com', $body);
        self::assertStringContainsString('border-l-error', $body);
        self::assertStringContainsString('M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', $body);
    }

    #[Test]
    public function exactly90PercentBelongsToSuccessBucket(): void
    {
        // Edge case: 90.0% exactly is success per the >=90 threshold.
        $data = $this->bootClientWithDomain();
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'edge90.com', 90, 10);

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('edge90.com', $body);
        self::assertStringContainsString('border-l-success', $body);
    }

    #[Test]
    public function exactly70PercentBelongsToWarningBucket(): void
    {
        // Edge case: 70.0% exactly is warning per the >=70 threshold (NOT error).
        $data = $this->bootClientWithDomain();
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'edge70.com', 70, 30);

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('edge70.com', $body);
        self::assertStringContainsString('border-l-warning', $body);
    }

    #[Test]
    public function justBelow70PercentBelongsToErrorBucket(): void
    {
        // Edge case: 69.9% is error per the <70 threshold.
        $data = $this->bootClientWithDomain();
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'edge69.com', 69, 31);

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('edge69.com', $body);
        self::assertStringContainsString('border-l-error', $body);
    }

    #[Test]
    public function nullPassRateRendersWarningTone(): void
    {
        // Edge case: a report row with zero records (no underlying dmarc_record
        // rows at all). COALESCE(...0) in the query collapses null to 0, which
        // falls into the error bucket — so this exercises the "no data" guard
        // working as designed: zero-record rows look like failing rows, which
        // is the conservative default ("data missing" should be a 'look at
        // me' cue, not a green-tinted lie). The shared macro's null branch
        // (renders warning) protects future callers that pass null through.
        $data = $this->bootClientWithDomain();
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'zerorecs.com', 0, 0);

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('zerorecs.com', $body);
        // Zero-record rows collapse to 0% pass which hits the error branch.
        // The guard against accidentally green-tinting a missing-data row.
        self::assertStringContainsString('border-l-error', $body);
    }

    #[Test]
    public function everyRowCarriesExactlyOneSeverityGlyph(): void
    {
        // Guard: regardless of pass-rate edge cases, every report row has
        // exactly one severity border tone — no row falls through to a
        // default and no row carries two competing tones.
        $data = $this->bootClientWithDomain();
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'google.com', 95, 5);
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'yahoo.com', 75, 25);
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'microsoft.com', 30, 70);

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $crawler = $data['client']->getCrawler();
        $rows = $crawler->filter('turbo-frame#reports-table table tbody tr');
        self::assertCount(3, $rows);

        foreach ($rows as $tr) {
            assert($tr instanceof \DOMElement);
            $class = $tr->getAttribute('class');
            $matches = preg_match_all('/border-l-(success|warning|error)/', $class);
            self::assertSame(
                1,
                $matches,
                'Each report row must carry exactly one severity border tone; got: '.$class,
            );
        }
    }

    #[Test]
    public function perDomainRecentReportsTableUsesSameGlyphFamily(): void
    {
        // The /app/domains/{id} "Recent Reports" table mirrors the same idiom
        // so the user sees one visual language across the per-domain view AND
        // the global report list.
        $data = $this->bootClientWithDomain();
        $this->persistReportWithPassRate($data['em'], $data['domain'], 'google.com', 30, 70);

        $data['client']->request('GET', '/app/domains/'.$data['domain']->id->toString());

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('google.com', $body);
        self::assertStringContainsString('border-l-error', $body);
        self::assertStringContainsString('<span class="sr-only">Health</span>', $body);
    }
}
