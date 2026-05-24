<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetTopSendersForDomain;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetTopSendersForDomainTest extends IntegrationTestCase
{
    public function testReturnsTopSendersOrderedByVolumeWithDkimSpfPassRates(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetTopSendersForDomain::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Top Sender Test',
            slug: 'top-sender-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'top-sender-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $authorizedSender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '1.1.1.1',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 1000,
            passRate: 95.0,
            organization: 'Cloudflare',
            isAuthorized: true,
        );
        $em->persist($authorizedSender);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'top-sender-ext-1-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'top-sender-test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        // Cloudflare = authorized, high volume
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.1.1.1',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'top-sender-test.com',
            resolvedOrg: 'Cloudflare',
        ));

        // Unknown IP, low volume, failing
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '8.8.8.8',
            count: 50,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: 'top-sender-test.com',
            resolvedOrg: 'Google',
        ));

        $em->flush();

        $results = $query->forDomain($domainId->toString(), [$team->id->toString()]);

        self::assertCount(2, $results);
        self::assertSame('Cloudflare', $results[0]->displayLabel);
        self::assertSame(100, $results[0]->totalMessages);
        self::assertSame(100.0, $results[0]->dkimPassRate);
        self::assertSame(100.0, $results[0]->spfPassRate);
        self::assertTrue($results[0]->senderIsAuthorized);
        self::assertSame($authorizedSender->id->toString(), $results[0]->knownSenderId);

        self::assertSame('Google', $results[1]->displayLabel);
        self::assertSame(0.0, $results[1]->dkimPassRate);
        self::assertSame(0.0, $results[1]->spfPassRate);
        self::assertNull($results[1]->senderIsAuthorized);
        self::assertNull($results[1]->knownSenderId);
    }

    public function testRespectsLimit(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetTopSendersForDomain::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Limit Test',
            slug: 'limit-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'limit-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'limit-ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('2024-04-01'),
            dateRangeEnd: new \DateTimeImmutable('2024-04-02'),
            policyDomain: 'limit-test.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: 'data',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        for ($i = 1; $i <= 7; ++$i) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '10.0.0.'.$i,
                count: 100 - $i,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: 'limit-test.com',
                resolvedOrg: 'Org-'.$i,
            ));
        }

        $em->flush();

        $results = $query->forDomain($domainId->toString(), [$team->id->toString()], limit: 5);

        self::assertCount(5, $results);
    }

    public function testReturnsEmptyForEmptyTeamList(): void
    {
        $query = $this->getService(GetTopSendersForDomain::class);

        $results = $query->forDomain(Uuid::uuid7()->toString(), []);

        self::assertSame([], $results);
    }

    public function testReturnsEmptyForDomainWithNoRecords(): void
    {
        $query = $this->getService(GetTopSendersForDomain::class);

        $results = $query->forDomain(Uuid::uuid7()->toString(), [Uuid::uuid7()->toString()]);

        self::assertSame([], $results);
    }

    public function testSummaryCountsAuthorizedAndUnknownAndUniqueIps(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetTopSendersForDomain::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Summary Test',
            slug: 'summary-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'summary-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '1.1.1.1',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 100,
            passRate: 100.0,
            isAuthorized: true,
        ));
        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '2.2.2.2',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 50,
            passRate: 80.0,
            isAuthorized: true,
        ));
        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '3.3.3.3',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 10,
            passRate: 20.0,
            isAuthorized: false,
        ));

        $em->flush();

        $summary = $query->summaryForDomain($domainId->toString(), [$team->id->toString()]);

        self::assertSame(2, $summary->authorizedCount);
        self::assertSame(1, $summary->unknownCount);
        self::assertSame(3, $summary->uniqueIpCount);
    }

    public function testSummaryReturnsZerosForEmptyTeamList(): void
    {
        $query = $this->getService(GetTopSendersForDomain::class);

        $summary = $query->summaryForDomain(Uuid::uuid7()->toString(), []);

        self::assertSame(0, $summary->authorizedCount);
        self::assertSame(0, $summary->unknownCount);
        self::assertSame(0, $summary->uniqueIpCount);
    }

    public function testSummaryReturnsZerosForDomainWithNoSenders(): void
    {
        $query = $this->getService(GetTopSendersForDomain::class);

        $summary = $query->summaryForDomain(Uuid::uuid7()->toString(), [Uuid::uuid7()->toString()]);

        self::assertSame(0, $summary->authorizedCount);
        self::assertSame(0, $summary->unknownCount);
        self::assertSame(0, $summary->uniqueIpCount);
    }

    public function testForDomainDoesNotReturnDataForOtherTeam(): void
    {
        // Cross-tenant guard: a valid domainId scoped against a foreign
        // team's id must return zero rows. Without this, a future change
        // dropping the team_id IN (:teamIds) clause would silently leak
        // sender breakdowns across teams.
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetTopSendersForDomain::class);

        $teamA = new Team(
            id: Uuid::uuid7(),
            name: 'Team A',
            slug: 'team-a-cross-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $teamB = new Team(
            id: Uuid::uuid7(),
            name: 'Team B',
            slug: 'team-b-cross-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($teamA);
        $em->persist($teamB);

        $domainAId = Uuid::uuid7();
        $domainA = new MonitoredDomain(
            id: $domainAId,
            team: $teamA,
            domain: 'cross-tenant-a.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domainA);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domainA,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $domainA->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '9.9.9.9',
            count: 100,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $domainA->domain,
        ));
        $em->flush();

        $result = $query->forDomain($domainAId->toString(), [$teamB->id->toString()]);

        self::assertSame([], $result);
    }

    public function testSummaryDoesNotReturnDataForOtherTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetTopSendersForDomain::class);

        $teamA = new Team(
            id: Uuid::uuid7(),
            name: 'Team A summary',
            slug: 'team-a-summary-cross-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $teamB = new Team(
            id: Uuid::uuid7(),
            name: 'Team B summary',
            slug: 'team-b-summary-cross-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($teamA);
        $em->persist($teamB);

        $domainAId = Uuid::uuid7();
        $domainA = new MonitoredDomain(
            id: $domainAId,
            team: $teamA,
            domain: 'cross-tenant-summary-a.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domainA);

        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domainA,
            sourceIp: '8.8.8.8',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 50,
            passRate: 100.0,
            isAuthorized: true,
        ));
        $em->flush();

        $summary = $query->summaryForDomain($domainAId->toString(), [$teamB->id->toString()]);

        self::assertSame(0, $summary->authorizedCount);
        self::assertSame(0, $summary->unknownCount);
        self::assertSame(0, $summary->uniqueIpCount);
    }
}
