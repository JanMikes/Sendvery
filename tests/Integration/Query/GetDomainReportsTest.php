<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainReports;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetDomainReportsTest extends IntegrationTestCase
{
    public function testReturnsReportsForDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainReports::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Reports Query',
            slug: 'reports-query-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'reports-query.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-reports-query-1',
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'reports-query.com',
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
            count: 50,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'reports-query.com',
        );
        $em->persist($record);
        $em->flush();

        $results = $query->forDomain($domainId->toString());

        self::assertCount(1, $results);
        self::assertSame('google.com', $results[0]->reporterOrg);
        self::assertSame(1, $results[0]->recordCount);
        self::assertGreaterThan(0.0, $results[0]->passRate);
    }

    public function testPagination(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainReports::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Paging',
            slug: 'paging-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'paging-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        for ($i = 0; $i < 3; $i++) {
            $report = new DmarcReport(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                reporterOrg: 'reporter-' . $i,
                reporterEmail: 'test@test.com',
                externalReportId: 'ext-page-' . $i,
                dateRangeBegin: new \DateTimeImmutable('2024-04-0' . ($i + 1)),
                dateRangeEnd: new \DateTimeImmutable('2024-04-0' . ($i + 2)),
                policyDomain: 'paging-test.com',
                policyAdkim: DmarcAlignment::Relaxed,
                policyAspf: DmarcAlignment::Relaxed,
                policyP: DmarcPolicy::None,
                policySp: null,
                policyPct: 100,
                rawXml: 'data',
                processedAt: new \DateTimeImmutable(),
            );
            $em->persist($report);
        }
        $em->flush();

        $page1 = $query->forDomain($domainId->toString(), limit: 2, offset: 0);
        self::assertCount(2, $page1);

        $page2 = $query->forDomain($domainId->toString(), limit: 2, offset: 2);
        self::assertCount(1, $page2);
    }
}
