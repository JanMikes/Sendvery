<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetReportDetail;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetReportDetailTest extends IntegrationTestCase
{
    public function testReturnsReportWithRecords(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetReportDetail::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Detail Test',
            slug: 'detail-test-' . Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'detail-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $reportId = Uuid::uuid7();
        $report = new DmarcReport(
            id: $reportId,
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-detail-1',
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'detail-test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Strict,
            policyP: DmarcPolicy::Reject,
            policySp: DmarcPolicy::Quarantine,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $record1 = new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'detail-test.com',
            dkimDomain: 'detail-test.com',
            dkimSelector: 'sel1',
            spfDomain: 'detail-test.com',
        );
        $em->persist($record1);

        $record2 = new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '5.6.7.8',
            count: 5,
            disposition: Disposition::Reject,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: 'detail-test.com',
        );
        $em->persist($record2);
        $em->flush();

        $result = $query->forReport($reportId->toString());

        self::assertNotNull($result);
        self::assertSame('google.com', $result->reporterOrg);
        self::assertSame('ext-detail-1', $result->externalReportId);
        self::assertSame('r', $result->policyAdkim);
        self::assertSame('s', $result->policyAspf);
        self::assertSame('reject', $result->policyP);
        self::assertSame('quarantine', $result->policySp);
        self::assertCount(2, $result->records);
        // Ordered by count DESC
        self::assertSame(100, $result->records[0]->count);
        self::assertSame(5, $result->records[1]->count);
    }

    public function testReturnsNullForNonExistentReport(): void
    {
        $query = $this->getService(GetReportDetail::class);

        $result = $query->forReport(Uuid::uuid7()->toString());

        self::assertNull($result);
    }
}
