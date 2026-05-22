<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetReporterOrgs;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetReporterOrgsTest extends IntegrationTestCase
{
    public function testReturnsEmptyWhenNoTeams(): void
    {
        $query = $this->getService(GetReporterOrgs::class);

        self::assertSame([], $query->forTeams([]));
    }

    public function testReturnsEmptyWhenTeamHasNoReports(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetReporterOrgs::class);

        $teamId = Uuid::uuid7();
        $em->persist(new Team(
            id: $teamId,
            name: 'Empty Reporters',
            slug: 'empty-reporters-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        self::assertSame([], $query->forTeams([$teamId->toString()]));
    }

    public function testReturnsDistinctReportersSortedAlphabetically(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetReporterOrgs::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Reporters Test',
            slug: 'reporters-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'reporters.example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        foreach (['yahoo.com', 'google.com', 'google.com', 'microsoft.com'] as $i => $reporter) {
            $em->persist(new DmarcReport(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                reporterOrg: $reporter,
                reporterEmail: 'noreply@'.$reporter,
                externalReportId: 'ext-reporter-'.$i.'-'.Uuid::uuid7()->toString(),
                dateRangeBegin: new \DateTimeImmutable('-2 days'),
                dateRangeEnd: new \DateTimeImmutable('-1 day'),
                policyDomain: $domain->domain,
                policyAdkim: DmarcAlignment::Relaxed,
                policyAspf: DmarcAlignment::Relaxed,
                policyP: DmarcPolicy::None,
                policySp: null,
                policyPct: 100,
                rawXml: 'data',
                processedAt: new \DateTimeImmutable(),
            ));
        }
        $em->flush();

        $reporters = $query->forTeams([$teamId->toString()]);

        self::assertSame(['google.com', 'microsoft.com', 'yahoo.com'], $reporters);
    }

    public function testIsolatesAcrossTeams(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetReporterOrgs::class);

        $teamAId = Uuid::uuid7();
        $teamA = new Team(
            id: $teamAId,
            name: 'Team A',
            slug: 'team-a-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($teamA);

        $teamBId = Uuid::uuid7();
        $teamB = new Team(
            id: $teamBId,
            name: 'Team B',
            slug: 'team-b-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($teamB);

        $domainA = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $teamA,
            domain: 'team-a.example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domainA);

        $domainB = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $teamB,
            domain: 'team-b.example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domainB);

        $em->persist(new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domainA,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-isolation-a-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $domainA->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        ));

        $em->persist(new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domainB,
            reporterOrg: 'yahoo.com',
            reporterEmail: 'noreply@yahoo.com',
            externalReportId: 'ext-isolation-b-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $domainB->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        ));

        $em->flush();

        self::assertSame(['google.com'], $query->forTeams([$teamAId->toString()]));
        self::assertSame(['yahoo.com'], $query->forTeams([$teamBId->toString()]));
        self::assertSame(['google.com', 'yahoo.com'], $query->forTeams([$teamAId->toString(), $teamBId->toString()]));
    }
}
