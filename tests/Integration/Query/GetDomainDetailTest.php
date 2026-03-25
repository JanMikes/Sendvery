<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainDetail;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetDomainDetailTest extends IntegrationTestCase
{
    public function testReturnsDomainWithStats(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainDetail::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Detail Test',
            slug: 'detail-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'detail-test.com',
            createdAt: new \DateTimeImmutable(),
            dmarcPolicy: DmarcPolicy::Reject,
            isVerified: true,
        );
        $em->persist($domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-detail-1',
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'detail-test.com',
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
            headerFrom: 'detail-test.com',
        );
        $em->persist($record);
        $em->flush();

        $result = $query->forDomain($domainId->toString());

        self::assertNotNull($result);
        self::assertSame('detail-test.com', $result->domainName);
        self::assertSame('reject', $result->dmarcPolicy);
        self::assertTrue($result->isVerified);
        self::assertSame(1, $result->totalReports);
        self::assertSame(50, $result->totalMessages);
        self::assertGreaterThan(0.0, $result->passRate);
        self::assertSame(1, $result->uniqueSenders);
    }

    public function testReturnsNullForNonexistentDomain(): void
    {
        $query = $this->getService(GetDomainDetail::class);

        $result = $query->forDomain(Uuid::uuid7()->toString());

        self::assertNull($result);
    }
}
