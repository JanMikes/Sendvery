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

        $results = $query->forTeam($teamId->toString());

        self::assertCount(1, $results);
        self::assertSame('allreports.com', $results[0]->domainName);
        self::assertSame('google.com', $results[0]->reporterOrg);
        self::assertSame(1, $results[0]->recordCount);
        self::assertGreaterThan(0.0, $results[0]->passRate);
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

        $results = $query->forTeam($teamId->toString());

        self::assertCount(0, $results);
    }
}
