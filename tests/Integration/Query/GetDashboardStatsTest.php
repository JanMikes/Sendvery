<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDashboardStats;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetDashboardStatsTest extends IntegrationTestCase
{
    public function testReturnsStatsForTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDashboardStats::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Stats Test',
            slug: 'stats-test-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'stats-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-stats-1',
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: 'stats-test.com',
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
            headerFrom: 'stats-test.com',
        );
        $em->persist($record);
        $em->flush();

        $result = $query->forTeam($teamId->toString());

        self::assertSame(1, $result->totalDomains);
        self::assertSame(1, $result->totalReportsLast30Days);
        self::assertSame(100, $result->totalMessages);
        self::assertGreaterThan(0.0, $result->overallPassRate);
    }

    public function testReturnsZerosForEmptyTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDashboardStats::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Empty Stats',
            slug: 'empty-stats-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $result = $query->forTeam($teamId->toString());

        self::assertSame(0, $result->totalDomains);
        self::assertSame(0, $result->totalReportsLast30Days);
        self::assertSame(0.0, $result->overallPassRate);
        self::assertSame(0, $result->totalMessages);
    }
}
