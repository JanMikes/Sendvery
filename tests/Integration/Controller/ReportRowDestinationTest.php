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
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Every row of a reports table must open ITS OWN report.
 *
 * This guards the bug where clicking any row of /app/reports opened the bottom
 * row's report: the rows each carried a correct href, but the click target was
 * an `absolute inset-0` overlay inside a `<tr class="relative">`, and CSS leaves
 * positioning a table-row undefined — so every overlay resolved to the whole
 * table, they stacked, and the last one in DOM order won every click.
 *
 * WHAT THESE TESTS DO AND DO NOT PROTECT: a server-side DOM assertion cannot
 * reproduce browser hit-testing, so these tests cannot fail on the CSS defect
 * itself. What they lock in is the structure that makes hit-testing irrelevant —
 * one real, visible, per-row anchor whose href is that row's report — plus the
 * absence of any overlay-style click surface. If someone reintroduces a
 * stretched overlay, the "row destination is the visible label" assertions here
 * break, which is the earliest signal a test suite can give.
 */
final class ReportRowDestinationTest extends WebTestCase
{
    /**
     * Three reports with distinct date ranges, so the list order
     * (`date_range_end DESC`) is deterministic: newest → oldest.
     *
     * @return array{client: KernelBrowser, domainId: UuidInterface, orderedReportIds: list<string>}
     */
    private function bootClientWithThreeReports(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('row-dest')
            ->teamName('Row Destination')
            ->withDomain('rowdest.example')
            ->build();
        assert(null !== $persona->domain);

        $orderedReportIds = [];
        foreach ([1, 2, 3] as $daysAgo) {
            $report = $this->persistReport($em, $persona->domain, $daysAgo);
            $orderedReportIds[] = $report->id->toString();
        }
        $em->flush();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'domainId' => $persona->domain->id,
            'orderedReportIds' => $orderedReportIds,
        ];
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

        // The middle report is the failing one, mirroring the user report of a
        // mixed pass rate where the failing report was unreachable.
        $passing = 2 !== $daysAgo;
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: sprintf('10.0.0.%d', $daysAgo),
            count: 6,
            disposition: $passing ? Disposition::None : Disposition::Reject,
            dkimResult: $passing ? AuthResult::Pass : AuthResult::Fail,
            spfResult: $passing ? AuthResult::Pass : AuthResult::Fail,
            headerFrom: $domain->domain,
        ));

        return $report;
    }

    #[Test]
    public function everyReportsListRowOpensItsOwnReport(): void
    {
        $data = $this->bootClientWithThreeReports();

        $crawler = $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();

        /** @var list<string> $hrefs */
        $hrefs = $crawler->filter('table tbody tr a[data-row-link-target="link"]')->extract(['href']);

        self::assertCount(3, $hrefs, 'Each report row must expose exactly one destination link.');
        self::assertSame(
            array_map(static fn (string $id): string => '/app/reports/'.$id, $data['orderedReportIds']),
            $hrefs,
            'Row N must link to report N — not every row to the same report.',
        );
        self::assertSame($hrefs, array_values(array_unique($hrefs)), 'No two rows may share a destination.');
    }

    #[Test]
    public function everyDomainReportsRowOpensItsOwnReport(): void
    {
        $data = $this->bootClientWithThreeReports();

        $crawler = $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/reports');

        self::assertResponseIsSuccessful();

        /** @var list<string> $hrefs */
        $hrefs = $crawler->filter('table tbody tr a[data-row-link-target="link"]')->extract(['href']);

        self::assertSame(
            array_map(static fn (string $id): string => '/app/reports/'.$id, $data['orderedReportIds']),
            $hrefs,
            'The per-domain reports table must also give each row its own destination.',
        );
    }

    #[Test]
    public function reportsListRowDestinationIsTheVisibleLabelNotAnInvisibleOverlay(): void
    {
        // The row link has to be something the user can see, focus, middle-click
        // and copy — an empty anchor stretched over the row is none of those, and
        // was the source of the "every row opens the same report" bug.
        $data = $this->bootClientWithThreeReports();

        $crawler = $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('table tbody tr a[data-row-link-target="link"]');

        foreach ($links as $index => $node) {
            self::assertNotSame('', trim((string) $node->textContent), sprintf('Row %d link must have visible text.', $index));
        }
    }

    #[Test]
    public function wholeRowClickComesFromTheRowLinkControllerNotInlineJavascript(): void
    {
        $data = $this->bootClientWithThreeReports();

        $crawler = $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertCount(
            3,
            $crawler->filter('table tbody tr[data-controller~="row-link"][data-action="click->row-link#navigate"]'),
            'Row-click convenience must be wired through the row-link Stimulus controller.',
        );
        self::assertStringNotContainsString('onclick', (string) $data['client']->getResponse()->getContent());
    }

    #[Test]
    public function reportsPageDeclaresTheTableFrameIdExactlyOnce(): void
    {
        // The page template used to wrap the partial in a <turbo-frame> with the
        // same id the partial itself opens — two elements with one DOM id, which
        // makes Turbo's frame lookup ambiguous.
        $data = $this->bootClientWithThreeReports();

        $crawler = $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#reports-table'));
    }

    #[Test]
    public function domainReportsPageDeclaresTheTableFrameIdExactlyOnce(): void
    {
        $data = $this->bootClientWithThreeReports();

        $crawler = $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/reports');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#domain-reports-table'));
    }
}
