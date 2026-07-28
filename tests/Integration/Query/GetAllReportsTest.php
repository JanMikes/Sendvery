<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetAllReports;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetAllReportsTest extends IntegrationTestCase
{
    public function testReturnsReportsWithDomainName(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAllReports::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'All Reports Test',
            slug: 'all-reports-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'allreports.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-all-1',
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'allreports.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 10,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'allreports.com',
        ));
        $em->flush();

        $results = $query->forTeams([$teamId->toString()]);

        self::assertCount(1, $results);
        self::assertSame('allreports.com', $results[0]->domainName);
        self::assertSame('google.com', $results[0]->reporterOrg);
        self::assertSame(1, $results[0]->recordCount);
        self::assertGreaterThan(0.0, $results[0]->passRate);
    }

    public function testAReportThatObservedNoMessagesHasNoPassRateRatherThanZeroPercent(): void
    {
        // An aggregate report covering a period with no traffic is legal under
        // RFC 7489, and `ProcessDmarcReportHandler` persists the report before it
        // iterates records with no non-empty guard — so a zero-record report is
        // storable today. Grading it 0.0% puts a red "every message failed" row in
        // the reports list for a report that observed nothing at all.
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAllReports::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'No Records',
            slug: 'no-records-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'norecords.example',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->persist($this->report($domain, 'ext-empty-'.Uuid::uuid7()->toString()));
        $em->flush();

        $results = $query->forTeams([$teamId->toString()]);

        self::assertCount(1, $results);
        self::assertSame(0, $results[0]->recordCount, 'A count of nothing genuinely is zero.');
        self::assertNull($results[0]->passRate, 'A report that observed no messages has no pass rate to show.');
    }

    public function testAReportThatObservedNoMessagesIsNotListedAmongTheFailingOnes(): void
    {
        // The "failing only" chip is a triage list. A report with nothing in it
        // has no failure to triage, and putting it there sends the user hunting
        // for a problem that does not exist.
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAllReports::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'No Records Band',
            slug: 'no-records-band-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'norecords-band.example',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->persist($this->report($domain, 'ext-empty-band-'.Uuid::uuid7()->toString()));
        $em->flush();

        self::assertSame([], $query->forTeams([$teamId->toString()], passRateBand: 'low'));
        self::assertSame([], $query->forTeams([$teamId->toString()], passRateBand: 'medium'));
        self::assertSame([], $query->forTeams([$teamId->toString()], passRateBand: 'high'));
    }

    public function testPagingNeverRepeatsOrSkipsAReportWhenManyShareADate(): void
    {
        // Reporters send one aggregate report per day, so several reports sharing
        // a `date_range_end` is the normal case, not an edge case. Ordering by
        // that column alone leaves the order within a tie group unspecified —
        // PostgreSQL is free to return a different one per query — so page 2 could
        // repeat a row from page 1 and drop another entirely.
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAllReports::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Tied Dates',
            slug: 'tied-dates-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'tied-dates.example',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $seeded = [];
        for ($i = 0; $i < 30; ++$i) {
            $report = $this->report($domain, sprintf('ext-tied-%02d-', $i).Uuid::uuid7()->toString());
            $em->persist($report);
            $seeded[] = $report->id->toString();
        }
        $em->flush();

        $paged = [];
        for ($page = 0; $page < 3; ++$page) {
            foreach ($query->forTeams([$teamId->toString()], limit: 10, offset: $page * 10) as $row) {
                $paged[] = $row->reportId;
            }
        }

        sort($seeded);
        $unique = array_unique($paged);
        sort($unique);

        self::assertCount(30, $paged, 'Three pages of ten must yield thirty rows.');
        self::assertSame($seeded, $unique, 'Paging must show every report exactly once — no repeats, nothing skipped.');
    }

    public function testReportsSharingADateAreOrderedNewestFirstWithinThatDate(): void
    {
        // The tiebreaker has to be deterministic AND meaningful: UUID v7 ids sort
        // by creation time, so `id DESC` continues the "newest first" story the
        // date column starts, instead of an arbitrary physical row order.
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAllReports::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Tie Order',
            slug: 'tie-order-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'tie-order.example',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $seeded = [];
        for ($i = 0; $i < 5; ++$i) {
            $report = $this->report($domain, sprintf('ext-order-%02d-', $i).Uuid::uuid7()->toString());
            $em->persist($report);
            $seeded[] = $report->id->toString();
        }
        $em->flush();

        $returned = array_map(
            static fn ($row): string => $row->reportId,
            $query->forTeams([$teamId->toString()]),
        );

        rsort($seeded);
        self::assertSame($seeded, $returned);
    }

    private function report(MonitoredDomain $domain, string $externalId): DmarcReport
    {
        return new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: $externalId,
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
    }

    public function testReturnsEmptyForTeamWithNoReports(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetAllReports::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Empty All Reports',
            slug: 'empty-all-reports-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $results = $query->forTeams([$teamId->toString()]);

        self::assertCount(0, $results);
    }
}
