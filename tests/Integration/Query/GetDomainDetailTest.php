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
            dmarcVerifiedAt: new \DateTimeImmutable(),
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

        $result = $query->forDomain($domainId->toString(), [$team->id->toString()]);

        self::assertNotNull($result);
        self::assertSame('detail-test.com', $result->domainName);
        self::assertSame('reject', $result->dmarcPolicy);
        self::assertTrue($result->isVerified());
        self::assertNotNull($result->dmarcVerifiedAt);
        self::assertSame(1, $result->totalReports);
        self::assertSame(50, $result->totalMessages);
        self::assertGreaterThan(0.0, $result->passRate);
        self::assertSame(1, $result->uniqueSenders);
    }

    public function testReturnsNullForNonexistentDomain(): void
    {
        $query = $this->getService(GetDomainDetail::class);

        $result = $query->forDomain(Uuid::uuid7()->toString(), [Uuid::uuid7()->toString()]);

        self::assertNull($result);
    }

    public function testReturnsNullForCrossTenantDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainDetail::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Owner Team',
            slug: 'owner-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $em->persist(new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'cross-tenant.example',
            createdAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        $otherTeamId = Uuid::uuid7()->toString();

        self::assertNull($query->forDomain($domainId->toString(), [$otherTeamId]));
    }

    public function testForDomainReturnsNullWhenNoTeamIdsProvided(): void
    {
        $query = $this->getService(GetDomainDetail::class);

        self::assertNull($query->forDomain(Uuid::uuid7()->toString(), []));
    }

    public function testCountRecentReportsOnlyCountsWithinWindow(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainDetail::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Recent Reports',
            slug: 'recent-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'recent-reports.example',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        // Two recent reports (within 30 days) plus one stale (60 days old) —
        // only the two recent ones should be counted.
        foreach ([new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('-10 days'), new \DateTimeImmutable('-60 days')] as $i => $endAt) {
            $em->persist(new DmarcReport(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                reporterOrg: 'google.com',
                reporterEmail: 'noreply@google.com',
                externalReportId: 'recent-'.$i,
                dateRangeBegin: $endAt->modify('-1 day'),
                dateRangeEnd: $endAt,
                policyDomain: 'recent-reports.example',
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

        self::assertSame(
            2,
            $query->getRecentActivity($domainId->toString(), [$team->id->toString()])->reportsCount,
            'Only reports inside the 30-day trailing window should be counted.',
        );
    }

    public function testGetRecentActivityReturnsEmptyForEmptyTeamIds(): void
    {
        $query = $this->getService(GetDomainDetail::class);

        $activity = $query->getRecentActivity(Uuid::uuid7()->toString(), []);
        self::assertSame(0, $activity->reportsCount);
        self::assertSame(0.0, $activity->passRate);
    }

    public function testGetRecentActivityRespectsCrossTenantScope(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainDetail::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Scoped',
            slug: 'scoped-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'scoped-recent.example',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->persist(new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'scoped-recent',
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: 'scoped-recent.example',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        $otherTeamId = Uuid::uuid7()->toString();
        $activity = $query->getRecentActivity($domainId->toString(), [$otherTeamId]);
        self::assertSame(0, $activity->reportsCount);
        self::assertSame(0.0, $activity->passRate);
    }

    public function testGetRecentActivityComputesTrailingPassRate(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainDetail::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Trailing PR',
            slug: 'trailing-pr-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'trailing.example',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        // Old report (60d) with 100% fails — should NOT count toward the
        // trailing pass rate. Recent report (2d) with 100% passes — should.
        $oldReport = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'old-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-61 days'),
            dateRangeEnd: new \DateTimeImmutable('-60 days'),
            policyDomain: 'trailing.example',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($oldReport);
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $oldReport,
            sourceIp: '1.1.1.1',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: 'trailing.example',
        ));

        $newReport = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'new-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-3 days'),
            dateRangeEnd: new \DateTimeImmutable('-2 days'),
            policyDomain: 'trailing.example',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($newReport);
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $newReport,
            sourceIp: '2.2.2.2',
            count: 50,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'trailing.example',
        ));
        $em->flush();

        $activity = $query->getRecentActivity($domainId->toString(), [$team->id->toString()]);

        self::assertSame(1, $activity->reportsCount, 'Only the recent report should count.');
        self::assertSame(100.0, $activity->passRate, 'Trailing pass rate must ignore the old all-fail batch.');
    }
}
