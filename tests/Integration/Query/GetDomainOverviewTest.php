<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainOverview;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DomainHealthFilter;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetDomainOverviewTest extends IntegrationTestCase
{
    public function testReturnsDomainsWithStatistics(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Overview Test',
            slug: 'overview-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'overview-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-overview-1',
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'overview-test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $record = new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'overview-test.com',
        );
        $em->persist($record);
        $em->flush();

        $results = $query->forTeams([$teamId->toString()]);

        self::assertCount(1, $results);
        self::assertSame('overview-test.com', $results[0]->domainName);
        self::assertSame(1, $results[0]->totalReports);
        self::assertNotNull($results[0]->latestReportDate);
        self::assertGreaterThan(0.0, $results[0]->passRate);
    }

    public function testForTeamsWithEmptyTeamIdsReturnsEmptyArray(): void
    {
        $query = $this->getService(GetDomainOverview::class);

        self::assertSame([], $query->forTeams([]));
    }

    public function testCountForTeamsWithEmptyTeamIdsReturnsZero(): void
    {
        $query = $this->getService(GetDomainOverview::class);

        self::assertSame(0, $query->countForTeams([]));
    }

    public function testCountForTeamsReturnsRowCount(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Count Test',
            slug: 'count-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $em->persist(new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'count-a.example',
            createdAt: new \DateTimeImmutable(),
        ));
        $em->persist(new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'count-b.example',
            createdAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        self::assertSame(2, $query->countForTeams([$teamId->toString()]));
    }

    public function testForTeamsFiltersByHealthyStatus(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);
        [$teamId] = $this->seedHealthAttentionUnverifiedDomains($em);

        $results = $query->forTeams([$teamId], DomainHealthFilter::Healthy);

        self::assertCount(1, $results);
        self::assertSame('healthy.example', $results[0]->domainName);
    }

    public function testForTeamsFiltersByAttentionStatus(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);
        [$teamId] = $this->seedHealthAttentionUnverifiedDomains($em);

        $results = $query->forTeams([$teamId], DomainHealthFilter::Attention);

        self::assertCount(1, $results);
        self::assertSame('attention.example', $results[0]->domainName);
    }

    public function testForTeamsFiltersByUnverifiedStatus(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);
        [$teamId] = $this->seedHealthAttentionUnverifiedDomains($em);

        $results = $query->forTeams([$teamId], DomainHealthFilter::Unverified);

        self::assertCount(1, $results);
        self::assertSame('unverified.example', $results[0]->domainName);
    }

    /** @return array{0: string} */
    private function seedHealthAttentionUnverifiedDomains(EntityManagerInterface $em): array
    {
        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Filter Test',
            slug: 'filter-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $healthy = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'healthy.example',
            createdAt: new \DateTimeImmutable('-30 days'),
            dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
        );
        $em->persist($healthy);
        $this->seedReport($em, $healthy, pass: 10, fail: 0);

        $attention = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'attention.example',
            createdAt: new \DateTimeImmutable('-20 days'),
            dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
        );
        $em->persist($attention);
        $this->seedReport($em, $attention, pass: 3, fail: 7);

        $em->persist(new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'unverified.example',
            createdAt: new \DateTimeImmutable('-1 day'),
        ));

        $em->flush();

        return [$teamId->toString()];
    }

    private function seedReport(EntityManagerInterface $em, MonitoredDomain $domain, int $pass, int $fail): void
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
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

    public function testReturnsDomainWithNoReports(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Empty Test',
            slug: 'empty-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'empty-overview.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();

        $results = $query->forTeams([$teamId->toString()]);

        self::assertCount(1, $results);
        self::assertSame(0, $results[0]->totalReports);
        self::assertNull($results[0]->latestReportDate);
        self::assertSame(0.0, $results[0]->passRate);
    }
}
