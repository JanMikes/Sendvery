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

/**
 * Every clickable row on /app/domains/{id} must open ITS OWN destination.
 *
 * Same defect as the reports list: the "Recent Reports" and "Top Senders"
 * tables each put an `absolute inset-0` anchor inside a `<td>` and relied on
 * `position: relative` applied to a `<tr>` to be its containing block. CSS 2.1
 * §9.3.1 leaves that undefined, daisyUI's `.table` is itself relatively
 * positioned, so every overlay sized itself to the whole table, they stacked at
 * one z-index, and the last row in DOM order swallowed every click.
 *
 * A server-side DOM test cannot reproduce browser hit-testing. What it can lock
 * in is the structure that makes hit-testing irrelevant — one real, visible,
 * per-row anchor — plus the absence of any overlay-style click surface.
 */
final class DomainDetailRowDestinationTest extends WebTestCase
{
    #[Test]
    public function eachRecentReportsRowOpensItsOwnReport(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('detail-rowdest')
            ->teamName('Detail Row Destination')
            ->withDomain('detailrowdest.example')
            ->build();
        assert(null !== $persona->domain);

        $reportIds = [];
        foreach ([1, 2, 3] as $daysAgo) {
            $reportIds[] = $this->persistReport($em, $persona->domain, $daysAgo)->id->toString();
        }
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('[data-testid="domain-recent-reports-table"] tbody tr');
        self::assertCount(3, $rows, 'All three reports must be listed.');

        $destinations = [];
        foreach ($rows as $index => $row) {
            $rowCrawler = new \Symfony\Component\DomCrawler\Crawler($row);
            self::assertStringContainsString(
                'row-link',
                (string) $rowCrawler->attr('data-controller'),
                sprintf('Row %d must delegate its click to a real anchor.', $index),
            );

            $link = $rowCrawler->filter('[data-row-link-target="link"]');
            self::assertCount(1, $link, sprintf('Row %d must have exactly one destination anchor.', $index));
            self::assertNotSame('', trim($link->text()), 'The anchor must be a visible label, not an invisible overlay.');
            $destinations[] = (string) $link->attr('href');
        }

        self::assertCount(3, array_unique($destinations), 'Every row must point somewhere different.');
        foreach ($destinations as $href) {
            self::assertTrue(
                (bool) array_filter($reportIds, static fn (string $id): bool => str_contains($href, $id)),
                sprintf('"%s" must be one of the listed reports.', $href),
            );
        }
    }

    #[Test]
    public function theTopSendersRowsAreClickableWithoutAnOverlay(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('detail-senders')
            ->teamName('Detail Senders')
            ->withDomain('detailsenders.example')
            ->build();
        assert(null !== $persona->domain);

        $report = $this->persistReport($em, $persona->domain, 1);
        foreach (['10.0.1.1', '10.0.1.2'] as $ip) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: $ip,
                count: 25,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $persona->domain->domain,
            ));
        }
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('[data-testid="top-senders-table"] tbody tr');
        self::assertGreaterThan(1, $rows->count(), 'Needs more than one row for the last-row-wins bug to be possible.');

        foreach ($rows as $index => $row) {
            $rowCrawler = new \Symfony\Component\DomCrawler\Crawler($row);
            self::assertStringContainsString(
                'row-link',
                (string) $rowCrawler->attr('data-controller'),
                sprintf('Sender row %d must delegate its click to a real anchor.', $index),
            );
            $link = $rowCrawler->filter('[data-row-link-target="link"]');
            self::assertCount(1, $link, sprintf('Sender row %d must have exactly one destination anchor.', $index));
            self::assertNotSame('', trim($link->text()), 'The anchor must carry the sender label the user sees.');
        }
    }

    #[Test]
    public function noStretchedOverlayAnchorSurvivesOnThePage(): void
    {
        // The overlay pattern is the defect itself. Its absence is the guard.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()
            ->emailPrefix('detail-nooverlay')
            ->teamName('Detail No Overlay')
            ->withDomain('detailnooverlay.example')
            ->build();
        assert(null !== $persona->domain);

        $this->persistReport($em, $persona->domain, 1);
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('table a.absolute'),
            'No table row may navigate through a stretched overlay anchor.',
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
