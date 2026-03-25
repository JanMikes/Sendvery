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

        $results = $query->forTeam($teamId->toString());

        self::assertCount(1, $results);
        self::assertSame('overview-test.com', $results[0]->domainName);
        self::assertSame(1, $results[0]->totalReports);
        self::assertNotNull($results[0]->latestReportDate);
        self::assertGreaterThan(0.0, $results[0]->passRate);
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

        $results = $query->forTeam($teamId->toString());

        self::assertCount(1, $results);
        self::assertSame(0, $results[0]->totalReports);
        self::assertNull($results[0]->latestReportDate);
        self::assertSame(0.0, $results[0]->passRate);
    }
}
