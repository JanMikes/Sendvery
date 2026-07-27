<?php

declare(strict_types=1);

namespace App\Tests\Integration\Architecture;

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
use PHPUnit\Framework\ExpectationFailedException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Every row opened the same report" — the rendered half of the guard.
 *
 * {@see \App\Tests\Unit\Architecture\ClickableRowStructureGuardTest} scans
 * template source and so covers every table in the tree, but it can only prove
 * that a row's `href` is *derived from* the row. It cannot prove the values come
 * out different, because that depends on the query: a `GROUP BY` that collapses
 * rows, a join that repeats the first id, or a result DTO built from the wrong
 * column all produce identical hrefs from perfectly correct markup — and the user
 * sees exactly the reported symptom, "whichever row I click, I land on the same
 * report".
 *
 * So this test renders the reports tables with several distinct reports in the
 * database and checks the destinations really differ.
 *
 * HONEST LIMITATION, true of both halves: neither can reproduce the CSS
 * hit-testing failure that caused the original defect. There, the hrefs were all
 * correct and distinct; the browser simply routed every click to the last
 * overlay painted. A server-side DOM has no layout and no hit-testing. What the
 * two guards lock in together is the structure that makes hit-testing
 * irrelevant — one real, visible, per-row anchor, no overlays — plus the data
 * correctness that structure relies on.
 */
final class RenderedRowDestinationsGuardTest extends WebTestCase
{
    #[Test]
    public function everyRowOfTheTeamReportsListOpensItsOwnReport(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('rendered-rowdest')
            ->teamName('Rendered Row Destinations')
            ->withDomain('renderedrowdest.example')
            ->build();
        assert(null !== $persona->domain);

        foreach ([1, 2, 3, 4] as $daysAgo) {
            $this->persistReport($em, $persona->domain, $daysAgo);
        }
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $this->assertRowsOpenDistinctRecords($crawler, '/app/reports');
    }

    #[Test]
    public function everyRowOfThePerDomainReportsListOpensItsOwnReport(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('rendered-domrowdest')
            ->teamName('Rendered Domain Row Destinations')
            ->withDomain('rendereddomrowdest.example')
            ->build();
        assert(null !== $persona->domain);

        foreach ([1, 2, 3, 4] as $daysAgo) {
            $this->persistReport($em, $persona->domain, $daysAgo);
        }
        $em->flush();

        $client->loginUser($persona->user);
        $path = sprintf('/app/domains/%s/reports', $persona->domain->id->toString());
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $this->assertRowsOpenDistinctRecords($crawler, $path);
    }

    #[Test]
    public function theGuardItselfFailsWhenTwoRowsShareADestination(): void
    {
        // Proof the guard can fail, fed a synthetic DOM rather than a temporarily
        // sabotaged template: the injected-violation trick has to be trusted
        // ("I saw it go red once"), whereas this runs on every CI build and keeps
        // proving it.
        $table = new Crawler(<<<'HTML'
            <table><tbody>
                <tr><td><a href="/app/reports/aaa" data-row-link-target="link">reporter-1</a></td></tr>
                <tr><td><a href="/app/reports/aaa" data-row-link-target="link">reporter-2</a></td></tr>
            </tbody></table>
            HTML);

        $this->assertGuardRejects($table, 'point at the same URL');
    }

    #[Test]
    public function theGuardItselfFailsOnAnInvisibleOverlayAnchor(): void
    {
        $table = new Crawler(<<<'HTML'
            <table><tbody>
                <tr><td><a href="/app/reports/aaa" class="absolute inset-0 z-10" data-row-link-target="link"></a>acme.example</td></tr>
                <tr><td><a href="/app/reports/bbb" class="absolute inset-0 z-10" data-row-link-target="link"></a>okay.example</td></tr>
            </tbody></table>
            HTML);

        $this->assertGuardRejects($table, 'no visible label');
    }

    #[Test]
    public function theGuardItselfFailsOnARowWithTwoCompetingDestinations(): void
    {
        $table = new Crawler(<<<'HTML'
            <table><tbody>
                <tr><td><a href="/app/reports/aaa" data-row-link-target="link">a</a><a href="/app/domains/x" data-row-link-target="link">b</a></td></tr>
                <tr><td><a href="/app/reports/bbb" data-row-link-target="link">c</a></td></tr>
            </tbody></table>
            HTML);

        $this->assertGuardRejects($table, 'exactly one destination anchor');
    }

    private function assertGuardRejects(Crawler $table, string $expectedReason): void
    {
        try {
            $this->assertRowsOpenDistinctRecords($table, 'synthetic table');
        } catch (ExpectationFailedException $failure) {
            self::assertStringContainsString($expectedReason, $failure->getMessage());

            return;
        }

        self::fail(sprintf('The guard accepted a table it must reject (%s).', $expectedReason));
    }

    private function assertRowsOpenDistinctRecords(Crawler $crawler, string $page): void
    {
        $rows = $crawler->filter('table tbody tr');
        self::assertGreaterThan(
            1,
            $rows->count(),
            sprintf('%s needs more than one row for "every row opens the same record" to be observable at all.', $page),
        );

        $destinations = [];
        foreach ($rows as $index => $row) {
            $rowCrawler = new Crawler($row);
            $anchors = $rowCrawler->filter('[data-row-link-target="link"]');

            self::assertCount(
                1,
                $anchors,
                sprintf('Row %d of %s must hold exactly one destination anchor — the row\'s single source of truth for where a click goes.', $index, $page),
            );
            self::assertNotSame(
                '',
                trim($anchors->text()),
                sprintf('Row %d of %s navigates through an anchor with no visible label. An invisible click surface is the overlay anti-pattern returning under another name.', $index, $page),
            );

            $destinations[] = (string) $anchors->attr('href');
        }

        self::assertSame(
            $destinations,
            array_values(array_unique($destinations)),
            sprintf(
                'Two rows of %s point at the same URL, so clicking different rows lands the user on the same record — the exact defect users reported. Markup alone cannot cause this: check the query behind the table for a collapsed GROUP BY or a repeated id.',
                $page,
            ),
        );
    }

    private function persistReport(EntityManagerInterface $em, MonitoredDomain $domain, int $daysAgo): DmarcReport
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: sprintf('reporter-%d.example', $daysAgo),
            reporterEmail: sprintf('dmarc@reporter-%d.example', $daysAgo),
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable(sprintf('-%d days -1 hour', $daysAgo)),
            dateRangeEnd: new \DateTimeImmutable(sprintf('-%d days', $daysAgo)),
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

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: sprintf('10.0.0.%d', $daysAgo),
            count: 6,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $domain->domain,
        ));

        return $report;
    }
}
