<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainSenderBreakdown;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetDomainSenderBreakdownTest extends IntegrationTestCase
{
    public function testReturnsSendersGroupedByIp(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainSenderBreakdown::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Sender Test',
            slug: 'sender-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'sender-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-sender-1',
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'sender-test.com',
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
            sourceIp: '1.1.1.1',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'sender-test.com',
            resolvedOrg: 'Cloudflare',
        ));

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '8.8.8.8',
            count: 50,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: 'sender-test.com',
            resolvedOrg: 'Google',
        ));

        $em->flush();

        $results = $query->forDomain($domainId->toString());

        self::assertCount(2, $results);
        self::assertSame('1.1.1.1', $results[0]->sourceIp);
        self::assertSame(100, $results[0]->totalMessages);
        self::assertSame('Cloudflare', $results[0]->resolvedOrg);
    }

    public function testReturnsEmptyForDomainWithNoRecords(): void
    {
        $query = $this->getService(GetDomainSenderBreakdown::class);

        $results = $query->forDomain(Uuid::uuid7()->toString());

        self::assertCount(0, $results);
    }
}
