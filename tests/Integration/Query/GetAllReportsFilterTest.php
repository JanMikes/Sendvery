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
use Ramsey\Uuid\UuidInterface;

/**
 * Filter-permutation coverage for GetAllReports::forTeams(). Each test
 * builds a fresh team/domain/report fixture so test runs don't depend on
 * each other (DAMA wraps each in a transaction anyway).
 */
final class GetAllReportsFilterTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private GetAllReports $query;
    private Team $team;
    private UuidInterface $teamId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->query = $this->getService(GetAllReports::class);

        $this->teamId = Uuid::uuid7();
        $this->team = new Team(
            id: $this->teamId,
            name: 'Filter Test',
            slug: 'filter-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($this->team);
        $this->em->flush();
    }

    private function createDomain(string $domainName): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $this->team,
            domain: $domainName,
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $this->em->persist($domain);

        return $domain;
    }

    /**
     * @param array{begin: string, end: string}                          $dateRange
     * @param list<array{count: int, dkim: AuthResult, spf: AuthResult}> $records
     */
    private function createReport(
        MonitoredDomain $domain,
        string $reporterOrg,
        array $dateRange,
        array $records,
    ): DmarcReport {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: $reporterOrg,
            reporterEmail: 'noreply@'.$reporterOrg,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable($dateRange['begin']),
            dateRangeEnd: new \DateTimeImmutable($dateRange['end']),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $this->em->persist($report);

        foreach ($records as $r) {
            $this->em->persist(new DmarcRecord(
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

        return $report;
    }

    public function testReturnsEmptyWhenNoTeams(): void
    {
        self::assertSame([], $this->query->forTeams([]));
    }

    public function testDomainIdFilter(): void
    {
        $domainA = $this->createDomain('a.example.com');
        $domainB = $this->createDomain('b.example.com');

        $this->createReport($domainA, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domainB, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            domainId: $domainA->id->toString(),
        );

        self::assertCount(1, $results);
        self::assertSame('a.example.com', $results[0]->domainName);
    }

    public function testDomainIdsMultiFilter(): void
    {
        $domainA = $this->createDomain('a.example.com');
        $domainB = $this->createDomain('b.example.com');
        $domainC = $this->createDomain('c.example.com');

        foreach ([$domainA, $domainB, $domainC] as $i => $domain) {
            $this->createReport($domain, 'google.com', ['begin' => '2026-05-0'.($i + 1), 'end' => '2026-05-0'.($i + 2)], [
                ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
            ]);
        }
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            domainIds: [$domainA->id->toString(), $domainC->id->toString()],
        );

        self::assertCount(2, $results);
        $domains = array_map(static fn ($r) => $r->domainName, $results);
        self::assertContains('a.example.com', $domains);
        self::assertContains('c.example.com', $domains);
    }

    public function testReporterOrgsFilter(): void
    {
        $domain = $this->createDomain('example.com');
        $this->createReport($domain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domain, 'yahoo.com', ['begin' => '2026-05-03', 'end' => '2026-05-04'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domain, 'microsoft.com', ['begin' => '2026-05-05', 'end' => '2026-05-06'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            reporterOrgs: ['google.com', 'yahoo.com'],
        );

        self::assertCount(2, $results);
        $orgs = array_map(static fn ($r) => $r->reporterOrg, $results);
        self::assertContains('google.com', $orgs);
        self::assertContains('yahoo.com', $orgs);
    }

    public function testPassRateBandHigh(): void
    {
        $domain = $this->createDomain('example.com');
        // 100% pass
        $this->createReport($domain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        // 50% pass
        $this->createReport($domain, 'yahoo.com', ['begin' => '2026-05-03', 'end' => '2026-05-04'], [
            ['count' => 5, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
            ['count' => 5, 'dkim' => AuthResult::Fail, 'spf' => AuthResult::Fail],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            passRateBand: 'high',
        );

        self::assertCount(1, $results);
        self::assertSame('google.com', $results[0]->reporterOrg);
        self::assertGreaterThanOrEqual(90.0, $results[0]->passRate);
    }

    public function testPassRateBandMedium(): void
    {
        $domain = $this->createDomain('example.com');
        // 100% pass
        $this->createReport($domain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        // 80% pass — falls into medium
        $this->createReport($domain, 'yahoo.com', ['begin' => '2026-05-03', 'end' => '2026-05-04'], [
            ['count' => 8, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
            ['count' => 2, 'dkim' => AuthResult::Fail, 'spf' => AuthResult::Fail],
        ]);
        // 0% pass
        $this->createReport($domain, 'microsoft.com', ['begin' => '2026-05-05', 'end' => '2026-05-06'], [
            ['count' => 10, 'dkim' => AuthResult::Fail, 'spf' => AuthResult::Fail],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            passRateBand: 'medium',
        );

        self::assertCount(1, $results);
        self::assertSame('yahoo.com', $results[0]->reporterOrg);
    }

    public function testPassRateBandLow(): void
    {
        $domain = $this->createDomain('example.com');
        $this->createReport($domain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        // 0% pass — low
        $this->createReport($domain, 'yahoo.com', ['begin' => '2026-05-03', 'end' => '2026-05-04'], [
            ['count' => 10, 'dkim' => AuthResult::Fail, 'spf' => AuthResult::Fail],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            passRateBand: 'low',
        );

        self::assertCount(1, $results);
        self::assertSame('yahoo.com', $results[0]->reporterOrg);
    }

    public function testDateFromFilter(): void
    {
        $domain = $this->createDomain('example.com');

        $this->createReport($domain, 'google.com', ['begin' => '2026-01-01', 'end' => '2026-01-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domain, 'yahoo.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            dateFrom: new \DateTimeImmutable('2026-03-01'),
        );

        self::assertCount(1, $results);
        self::assertSame('yahoo.com', $results[0]->reporterOrg);
    }

    public function testDateToFilter(): void
    {
        $domain = $this->createDomain('example.com');

        $this->createReport($domain, 'google.com', ['begin' => '2026-01-01', 'end' => '2026-01-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domain, 'yahoo.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            dateTo: new \DateTimeImmutable('2026-03-01'),
        );

        self::assertCount(1, $results);
        self::assertSame('google.com', $results[0]->reporterOrg);
    }

    public function testDateRangeBothBounds(): void
    {
        $domain = $this->createDomain('example.com');

        $this->createReport($domain, 'google.com', ['begin' => '2026-01-01', 'end' => '2026-01-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domain, 'yahoo.com', ['begin' => '2026-03-01', 'end' => '2026-03-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domain, 'microsoft.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            dateFrom: new \DateTimeImmutable('2026-02-01'),
            dateTo: new \DateTimeImmutable('2026-04-01'),
        );

        self::assertCount(1, $results);
        self::assertSame('yahoo.com', $results[0]->reporterOrg);
    }

    public function testSearchFiltersByReporterOrg(): void
    {
        $domain = $this->createDomain('example.com');

        $this->createReport($domain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domain, 'yahoo.com', ['begin' => '2026-05-03', 'end' => '2026-05-04'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            search: 'goog',
        );

        self::assertCount(1, $results);
        self::assertSame('google.com', $results[0]->reporterOrg);
    }

    public function testSearchFiltersByDomain(): void
    {
        $domainA = $this->createDomain('searchable.example.com');
        $domainB = $this->createDomain('other.example.com');

        $this->createReport($domainA, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domainB, 'google.com', ['begin' => '2026-05-03', 'end' => '2026-05-04'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            search: 'searchable',
        );

        self::assertCount(1, $results);
        self::assertSame('searchable.example.com', $results[0]->domainName);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $domain = $this->createDomain('example.com');
        $this->createReport($domain, 'GOOGLE.COM', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            search: 'google',
        );

        self::assertCount(1, $results);
    }

    public function testTeamScopingIsMandatory(): void
    {
        $domain = $this->createDomain('example.com');
        $this->createReport($domain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        // Different team ID — nothing returned even with valid domain id filter
        $otherTeamId = Uuid::uuid7();
        $results = $this->query->forTeams(
            [$otherTeamId->toString()],
            domainId: $domain->id->toString(),
        );

        self::assertCount(0, $results);
    }

    public function testCrossTeamDomainIdInFilterDoesNotLeakData(): void
    {
        // Build a domain on a SECOND team — pass its UUID through the first
        // team's query and verify mandatory team scope WHERE blocks the leak.
        $otherTeamId = Uuid::uuid7();
        $otherTeam = new Team(
            id: $otherTeamId,
            name: 'Other Team',
            slug: 'other-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($otherTeam);

        $otherDomain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $otherTeam,
            domain: 'sensitive.example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $otherDomain->popEvents();
        $this->em->persist($otherDomain);

        $this->createReport($otherDomain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            domainId: $otherDomain->id->toString(),
        );

        self::assertCount(0, $results);
    }

    public function testCombinedFilters(): void
    {
        $domain = $this->createDomain('example.com');

        $this->createReport($domain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domain, 'google.com', ['begin' => '2026-01-01', 'end' => '2026-01-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domain, 'yahoo.com', ['begin' => '2026-05-03', 'end' => '2026-05-04'], [
            ['count' => 10, 'dkim' => AuthResult::Fail, 'spf' => AuthResult::Fail],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            reporterOrgs: ['google.com'],
            passRateBand: 'high',
            dateFrom: new \DateTimeImmutable('2026-04-01'),
        );

        self::assertCount(1, $results);
        self::assertSame('google.com', $results[0]->reporterOrg);
    }

    public function testLegacyDomainIdParamFiltersCorrectly(): void
    {
        // Migrated from former GetDomainReportsTest::testReturnsReportsForDomain.
        $domain = $this->createDomain('reports-query.com');

        $this->createReport($domain, 'google.com', ['begin' => '2024-04-01', 'end' => '2024-04-02'], [
            ['count' => 50, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            domainId: $domain->id->toString(),
        );

        self::assertCount(1, $results);
        self::assertSame('google.com', $results[0]->reporterOrg);
        self::assertSame(1, $results[0]->recordCount);
        self::assertGreaterThan(0.0, $results[0]->passRate);
    }

    public function testPaginationWithDomainIdParam(): void
    {
        // Migrated from former GetDomainReportsTest::testPagination.
        $domain = $this->createDomain('paging-test.com');

        for ($i = 0; $i < 3; ++$i) {
            $this->createReport(
                $domain,
                'reporter-'.$i,
                ['begin' => '2024-04-0'.($i + 1), 'end' => '2024-04-0'.($i + 2)],
                [],
            );
        }
        $this->em->flush();

        $teamIds = [$this->teamId->toString()];
        $page1 = $this->query->forTeams($teamIds, limit: 2, offset: 0, domainId: $domain->id->toString());
        self::assertCount(2, $page1);

        $page2 = $this->query->forTeams($teamIds, limit: 2, offset: 2, domainId: $domain->id->toString());
        self::assertCount(1, $page2);
    }

    public function testDomainIdsEmptyArrayIsNoFilter(): void
    {
        $domain = $this->createDomain('example.com');
        $this->createReport($domain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            domainIds: [],
        );

        self::assertCount(1, $results);
    }

    public function testReporterOrgsEmptyArrayIsNoFilter(): void
    {
        $domain = $this->createDomain('example.com');
        $this->createReport($domain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams(
            [$this->teamId->toString()],
            reporterOrgs: [],
        );

        self::assertCount(1, $results);
    }

    public function testNullPassRateBandReturnsAll(): void
    {
        $domain = $this->createDomain('example.com');
        // mixed pass rates
        $this->createReport($domain, 'google.com', ['begin' => '2026-05-01', 'end' => '2026-05-02'], [
            ['count' => 10, 'dkim' => AuthResult::Pass, 'spf' => AuthResult::Pass],
        ]);
        $this->createReport($domain, 'yahoo.com', ['begin' => '2026-05-03', 'end' => '2026-05-04'], [
            ['count' => 10, 'dkim' => AuthResult::Fail, 'spf' => AuthResult::Fail],
        ]);
        $this->em->flush();

        $results = $this->query->forTeams([$this->teamId->toString()]);

        self::assertCount(2, $results);
    }
}
