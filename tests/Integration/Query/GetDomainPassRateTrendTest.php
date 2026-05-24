<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainPassRateTrend;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetDomainPassRateTrendTest extends IntegrationTestCase
{
    public function testReturnsTrendForDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainPassRateTrend::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Trend Test',
            slug: 'trend-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'trend-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();

        $results = $query->forDomain($domainId->toString(), [$team->id->toString()], days: 7);

        self::assertCount(8, $results); // 7 days + today
        foreach ($results as $result) {
            self::assertNotEmpty($result->date);
            self::assertSame(0, $result->passCount);
            self::assertSame(0, $result->failCount);
        }
    }

    public function testReturnsTrendForTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainPassRateTrend::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Team Trend',
            slug: 'team-trend-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $results = $query->forTeams([$teamId->toString()], days: 3);

        self::assertCount(4, $results); // 3 days + today
    }

    public function testForDomainsReturnsTenBucketsPerDomainForDefaultWindow(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainPassRateTrend::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Multi Domain Sparkline',
            slug: 'sparkline-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainA = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'spark-a.example',
            createdAt: new \DateTimeImmutable('-40 days'),
        );
        $domainB = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'spark-b.example',
            createdAt: new \DateTimeImmutable('-40 days'),
        );
        $em->persist($domainA);
        $em->persist($domainB);
        $em->flush();

        $results = $query->forDomains(
            domainIds: [$domainA->id->toString(), $domainB->id->toString()],
            teamIds: [$team->id->toString()],
        );

        // Default window (30 days / 3-day buckets) yields 10 buckets per domain.
        self::assertCount(2, $results);
        self::assertCount(10, $results[$domainA->id->toString()]);
        self::assertCount(10, $results[$domainB->id->toString()]);
        // No reports → every bucket pass-rate floors at 0.0.
        foreach ($results[$domainA->id->toString()] as $bucket) {
            self::assertSame(0.0, $bucket);
        }
    }

    public function testForDomainsReflectsRecentReportPassRate(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainPassRateTrend::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Sparkline Reports',
            slug: 'sparkline-reports-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'sparkline-data.example',
            createdAt: new \DateTimeImmutable('-40 days'),
        );
        $em->persist($domain);

        // One report landing in the most-recent bucket (-1 day) with 7/10 pass.
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-spark-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-1 day'),
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
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 7,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $domain->domain,
        ));
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '5.6.7.8',
            count: 3,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: $domain->domain,
        ));
        $em->flush();

        $buckets = $query->forDomains(
            domainIds: [$domain->id->toString()],
            teamIds: [$team->id->toString()],
        )[$domain->id->toString()];

        // 10 buckets total, last bucket holds the seeded report — 7 pass / 10 = 70%.
        self::assertCount(10, $buckets);
        self::assertSame(70.0, $buckets[9]);
        // Older buckets (no reports) remain at zero.
        self::assertSame(0.0, $buckets[0]);
    }

    public function testForDomainsScopesToTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainPassRateTrend::class);

        $teamA = new Team(
            id: Uuid::uuid7(),
            name: 'Sparkline Tenant A',
            slug: 'sparkline-a-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $teamB = new Team(
            id: Uuid::uuid7(),
            name: 'Sparkline Tenant B',
            slug: 'sparkline-b-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($teamA);
        $em->persist($teamB);

        $domainA = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $teamA,
            domain: 'tenant-a.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domainB = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $teamB,
            domain: 'tenant-b.example',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domainA);
        $em->persist($domainB);
        $em->flush();

        // teamA asks for both domain IDs — only its own domain may return.
        $results = $query->forDomains(
            domainIds: [$domainA->id->toString(), $domainB->id->toString()],
            teamIds: [$teamA->id->toString()],
        );

        self::assertArrayHasKey($domainA->id->toString(), $results);
        self::assertArrayNotHasKey($domainB->id->toString(), $results);
    }

    public function testForDomainsReturnsEmptyArrayForEmptyDomainIds(): void
    {
        $query = $this->getService(GetDomainPassRateTrend::class);

        self::assertSame([], $query->forDomains([], ['00000000-0000-7000-8000-000000000000']));
    }

    public function testForDomainsReturnsEmptyArrayForEmptyTeamIds(): void
    {
        $query = $this->getService(GetDomainPassRateTrend::class);

        self::assertSame([], $query->forDomains(['00000000-0000-7000-8000-000000000000'], []));
    }

    public function testForDomainReturnsEmptyArrayForEmptyTeamIds(): void
    {
        $query = $this->getService(GetDomainPassRateTrend::class);

        self::assertSame([], $query->forDomain('00000000-0000-7000-8000-000000000000', []));
    }

    public function testForTeamsReturnsEmptyArrayForEmptyTeamIds(): void
    {
        $query = $this->getService(GetDomainPassRateTrend::class);

        self::assertSame([], $query->forTeams([]));
    }
}
