<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\SenderIdentity;
use App\Entity\Team;
use App\Query\GetReportDetail;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SenderRole;
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
            slug: 'detail-test-'.Uuid::uuid7()->toString(),
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

        $result = $query->forReport($reportId->toString(), [$team->id->toString()]);

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

    public function testReturnsNothingWhenTheCallerCanReadNoTeams(): void
    {
        $query = $this->getService(GetReportDetail::class);

        self::assertNull($query->forReport(Uuid::uuid7()->toString(), []));
    }

    public function testReturnsNullForNonExistentReport(): void
    {
        $query = $this->getService(GetReportDetail::class);

        $result = $query->forReport(Uuid::uuid7()->toString(), [Uuid::uuid7()->toString()]);

        self::assertNull($result);
    }

    /**
     * The record table is the evidence, so it stays per address — but an
     * address whose enrichment was never written onto the record (ingested
     * before enrichment existed, or replayed after a purge) is still named by
     * the shared identity cache, and is labelled with what it is. A gateway
     * failing SPF is forwarding, and the reader has to be told so.
     */
    public function testRecordsAreNamedAndClassifiedFromTheSharedIdentityCache(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetReportDetail::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Identity Detail',
            slug: 'identity-detail-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'identity-detail.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $reportId = Uuid::uuid7();
        $identityReport = new DmarcReport(
            id: $reportId,
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('2026-07-20'),
            dateRangeEnd: new \DateTimeImmutable('2026-07-21'),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($identityReport);

        $em->persist(new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: '52.212.19.177',
            resolvedAt: new \DateTimeImmutable('2026-07-21'),
            hostname: 'eu.cloud-sec-av.com',
            registrableDomain: 'cloud-sec-av.com',
            organization: null,
            role: SenderRole::Forwarder,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable('2026-07-21'),
        ));

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $identityReport,
            sourceIp: '52.212.19.177',
            count: 9,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Fail,
            headerFrom: $domain->domain,
        ));

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $identityReport,
            sourceIp: '198.51.100.200',
            count: 2,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: $domain->domain,
        ));

        $em->flush();

        $result = $query->forReport($reportId->toString(), [$team->id->toString()]);

        self::assertNotNull($result);
        self::assertCount(2, $result->records);

        self::assertSame('eu.cloud-sec-av.com', $result->records[0]->resolvedHostname);
        self::assertSame(SenderRole::Forwarder, $result->records[0]->senderRole);

        self::assertSame('198.51.100.200', $result->records[1]->sourceIp, 'An address with no identity row is still listed.');
        self::assertNull($result->records[1]->resolvedHostname);
        self::assertNull($result->records[1]->senderRole, 'No identity row means not classified, which is not a verdict.');
    }
}
