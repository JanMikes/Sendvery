<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainOverview;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
use App\Value\DomainHealthFilter;
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

        $results = $query->forTeams([$teamId->toString()]);

        self::assertCount(1, $results);
        self::assertSame('overview-test.com', $results[0]->domainName);
        self::assertSame(1, $results[0]->totalReports);
        self::assertNotNull($results[0]->latestReportDate);
        self::assertGreaterThan(0.0, $results[0]->passRate);
    }

    public function testForTeamsWithEmptyTeamIdsReturnsEmptyArray(): void
    {
        $query = $this->getService(GetDomainOverview::class);

        self::assertSame([], $query->forTeams([]));
    }

    public function testCountForTeamsWithEmptyTeamIdsReturnsZero(): void
    {
        $query = $this->getService(GetDomainOverview::class);

        self::assertSame(0, $query->countForTeams([]));
    }

    public function testCountForTeamsReturnsRowCount(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Count Test',
            slug: 'count-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $em->persist(new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'count-a.example',
            createdAt: new \DateTimeImmutable(),
        ));
        $em->persist(new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'count-b.example',
            createdAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        self::assertSame(2, $query->countForTeams([$teamId->toString()]));
    }

    public function testForTeamsFiltersByHealthyStatus(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);
        [$teamId] = $this->seedHealthAttentionUnverifiedDomains($em);

        $results = $query->forTeams([$teamId], DomainHealthFilter::Healthy);

        self::assertCount(1, $results);
        self::assertSame('healthy.example', $results[0]->domainName);
    }

    public function testForTeamsFiltersByAttentionStatus(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);
        [$teamId] = $this->seedHealthAttentionUnverifiedDomains($em);

        $results = $query->forTeams([$teamId], DomainHealthFilter::Attention);

        self::assertCount(1, $results);
        self::assertSame('attention.example', $results[0]->domainName);
    }

    public function testForTeamsFiltersByUnverifiedStatus(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);
        [$teamId] = $this->seedHealthAttentionUnverifiedDomains($em);

        $results = $query->forTeams([$teamId], DomainHealthFilter::Unverified);

        self::assertCount(1, $results);
        self::assertSame('unverified.example', $results[0]->domainName);
    }

    /** @return array{0: string} */
    private function seedHealthAttentionUnverifiedDomains(EntityManagerInterface $em): array
    {
        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Filter Test',
            slug: 'filter-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        // Both verified domains carry a full set of passing DNS checks. Without
        // them the health classifier calls BOTH of them Attention (it requires all
        // four protocols configured before it will say Healthy), so a fixture that
        // omitted them made the "healthy" domain healthy only in the eyes of the
        // looser SQL filter — the exact disagreement between the chip and the card
        // badge that W3 removes. Here the pass rate is the only difference between
        // the two, which is what these tests are actually about.
        $healthy = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'healthy.example',
            createdAt: new \DateTimeImmutable('-30 days'),
            dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
        );
        $em->persist($healthy);
        $this->seedPassingDnsChecks($em, $healthy);
        $this->seedReport($em, $healthy, pass: 10, fail: 0);

        $attention = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'attention.example',
            createdAt: new \DateTimeImmutable('-20 days'),
            dmarcVerifiedAt: new \DateTimeImmutable('-10 days'),
        );
        $em->persist($attention);
        $this->seedPassingDnsChecks($em, $attention);
        $this->seedReport($em, $attention, pass: 3, fail: 7);

        $em->persist(new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'unverified.example',
            createdAt: new \DateTimeImmutable('-1 day'),
        ));

        $em->flush();

        return [$teamId->toString()];
    }

    private function seedReport(EntityManagerInterface $em, MonitoredDomain $domain, int $pass, int $fail): void
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $em->persist($report);

        if ($pass > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '1.2.3.4',
                count: $pass,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $domain->domain,
            ));
        }

        if ($fail > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '5.6.7.8',
                count: $fail,
                disposition: Disposition::None,
                dkimResult: AuthResult::Fail,
                spfResult: AuthResult::Fail,
                headerFrom: $domain->domain,
            ));
        }
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

        $results = $query->forTeams([$teamId->toString()]);

        self::assertCount(1, $results);
        self::assertSame(0, $results[0]->totalReports);
        self::assertNull($results[0]->latestReportDate);
        // A domain nobody has reported on has NO pass rate. 0.0 would claim
        // every message failed authentication, which is a different fact.
        self::assertNull(
            $results[0]->passRate,
            'A domain with no DMARC records must report no pass rate, never 0%.',
        );
        self::assertFalse($results[0]->hasPassRateData());
        self::assertTrue($results[0]->isAwaitingFirstReport());
    }

    public function testAVerifiedDomainAwaitingItsFirstReportIsNotListedAsNeedingAttention(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);
        $teamId = $this->seedVerifiedDomainWithoutReports($em);

        $results = $query->forTeams([$teamId], DomainHealthFilter::Attention);

        self::assertSame(
            [],
            $results,
            'A correctly verified domain that simply has no reports yet must not be accused of needing attention.',
        );
    }

    public function testAVerifiedDomainAwaitingItsFirstReportStaysInTheHealthyFilter(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);
        $teamId = $this->seedVerifiedDomainWithoutReports($em);

        $results = $query->forTeams([$teamId], DomainHealthFilter::Healthy);

        self::assertCount(1, $results);
        self::assertSame('awaiting-report.example', $results[0]->domainName);
    }

    public function testAnUnverifiedDomainWithoutReportsIsNeverListedAsHealthy(): void
    {
        // "No reports" no longer disqualifies a domain from Healthy, so the
        // filter has to exclude unverified domains explicitly — otherwise the
        // Healthy list would contain cards rendering the red unverified glyph.
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainOverview::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Unverified Healthy Guard',
            slug: 'unverified-healthy-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->persist(new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'never-verified.example',
            createdAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        self::assertSame([], $query->forTeams([$teamId->toString()], DomainHealthFilter::Healthy));
    }

    private function seedVerifiedDomainWithoutReports(EntityManagerInterface $em): string
    {
        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Awaiting Report',
            slug: 'awaiting-report-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        // Correctly set up in every respect — verified DMARC and four passing DNS
        // checks — and simply has no reports yet. That is the whole point of these
        // two tests: the ONLY thing absent is the pass rate.
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'awaiting-report.example',
            createdAt: new \DateTimeImmutable('-1 hour'),
            dmarcVerifiedAt: new \DateTimeImmutable('-30 minutes'),
        );
        $em->persist($domain);
        $this->seedPassingDnsChecks($em, $domain);
        $em->flush();

        return $teamId->toString();
    }

    /**
     * The newest `dns_check_result` per protocol, all passing. `DomainHealthClassifier`
     * requires all four before it will call a domain Healthy, and the `?status=`
     * filter now transcribes that same rule, so a fixture that leaves them out is
     * describing a domain that needs attention.
     */
    private function seedPassingDnsChecks(EntityManagerInterface $em, MonitoredDomain $domain): void
    {
        foreach ([DnsCheckType::Spf, DnsCheckType::Dkim, DnsCheckType::Dmarc, DnsCheckType::Mx] as $type) {
            $check = new DnsCheckResult(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                type: $type,
                checkedAt: new \DateTimeImmutable('-1 hour'),
                rawRecord: 'record',
                isValid: true,
                issues: [],
                details: [],
                previousRawRecord: null,
                hasChanged: false,
                isFirstCheck: false,
            );
            $check->popEvents();
            $em->persist($check);
        }
    }
}
