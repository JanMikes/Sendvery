<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\SenderIdentity;
use App\Entity\Team;
use App\Entity\User;
use App\Query\GetTopSendersForDomain;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SenderRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetTopSendersForDomainTest extends IntegrationTestCase
{
    private function createDomain(string $slugPrefix): MonitoredDomain
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: $slugPrefix,
            slug: $slugPrefix.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $slugPrefix.'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        return $domain;
    }

    private function createReport(MonitoredDomain $domain): DmarcReport
    {
        $em = $this->getService(EntityManagerInterface::class);

        $report = new DmarcReport(
            id: Uuid::uuid7(),
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
        $em->persist($report);

        return $report;
    }

    private function persistRecord(
        DmarcReport $report,
        string $sourceIp,
        int $count,
        AuthResult $dkim,
        AuthResult $spf,
    ): void {
        $this->getService(EntityManagerInterface::class)->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: $sourceIp,
            count: $count,
            disposition: Disposition::None,
            dkimResult: $dkim,
            spfResult: $spf,
            headerFrom: $report->monitoredDomain->domain,
        ));
    }

    private function persistIdentity(
        string $sourceIp,
        string $hostname,
        string $registrableDomain,
        ?string $organization,
        SenderRole $role,
    ): void {
        $this->getService(EntityManagerInterface::class)->persist(new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: $sourceIp,
            resolvedAt: new \DateTimeImmutable('2026-07-21'),
            hostname: $hostname,
            registrableDomain: $registrableDomain,
            organization: $organization,
            role: $role,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable('2026-07-21'),
        ));
    }

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

    /**
     * The incident that produced DEC-059: one security-gateway product with a
     * node on three continents was three separate "senders" on the dashboard,
     * each too small to explain anything. No curated mapping exists for
     * cloud-sec-av.com and none ever will, so the registrable domain of the
     * PTR is what has to carry the identity.
     */
    public function testAGatewaysRegionalNodesAreOneSenderRatherThanThree(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetTopSendersForDomain::class);

        $domain = $this->createDomain('gateway-nodes');
        $report = $this->createReport($domain);

        $nodes = [
            '52.212.19.177' => 'eu.cloud-sec-av.com',
            '15.222.110.90' => 'ca.cloud-sec-av.com',
            '35.174.145.124' => 'us.cloud-sec-av.com',
        ];

        foreach ($nodes as $ip => $hostname) {
            $this->persistIdentity($ip, $hostname, 'cloud-sec-av.com', null, SenderRole::Forwarder);
            $this->persistRecord($report, $ip, 4, AuthResult::Pass, AuthResult::Fail);
        }

        $em->flush();

        $results = $query->forDomain($domain->id->toString(), [$domain->team->id->toString()]);

        self::assertCount(1, $results, 'One gateway product is one sender, whatever continent the node sits on.');
        self::assertSame('cloud-sec-av.com', $results[0]->displayLabel);
        self::assertSame(12, $results[0]->totalMessages, 'The whole product\'s volume is attributed to it, not split three ways.');
        self::assertSame(SenderRole::Forwarder, $results[0]->senderRole, 'Calling it a forwarder is what makes its SPF failures readable.');
    }

    /**
     * The customer's own outbound relay rotates through an IPv6 pool, which
     * used to fill the top-senders table with near-identical rows. The curated
     * organisation name wins the label when there is one, but the *grouping*
     * does not depend on that name existing.
     */
    public function testARotatingRelayPoolIsOneSenderUnderItsOrganisationName(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetTopSendersForDomain::class);

        $domain = $this->createDomain('relay-pool');
        $report = $this->createReport($domain);

        $pool = [
            '2a02:598:1111::1' => 'mxb-1-908.seznam.cz',
            '2a02:598:1111::2' => 'mxb-2-904.seznam.cz',
            '2a02:598:1111::3' => 'mxb-3-514.seznam.cz',
        ];

        foreach ($pool as $ip => $hostname) {
            $this->persistIdentity($ip, $hostname, 'seznam.cz', 'Seznam', SenderRole::Esp);
            $this->persistRecord($report, $ip, 10, AuthResult::Pass, AuthResult::Pass);
        }

        $em->flush();

        $results = $query->forDomain($domain->id->toString(), [$domain->team->id->toString()]);

        self::assertCount(1, $results);
        self::assertSame('Seznam', $results[0]->displayLabel);
        self::assertSame(30, $results[0]->totalMessages);
    }

    /**
     * Identity resolution is a lazily filled, bounded cache: an address can
     * legitimately have no row at all. That must cost the sender its name, not
     * its place in the table.
     */
    public function testAnAddressWithNoIdentityStillAppearsUnderItsOwnAddress(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetTopSendersForDomain::class);

        $domain = $this->createDomain('unidentified');
        $report = $this->createReport($domain);

        $this->persistIdentity('203.0.113.10', 'mail.identified.example', 'identified.example', null, SenderRole::Esp);
        $this->persistRecord($report, '203.0.113.10', 20, AuthResult::Pass, AuthResult::Pass);
        $this->persistRecord($report, '203.0.113.99', 7, AuthResult::Fail, AuthResult::Fail);

        $em->flush();

        $results = $query->forDomain($domain->id->toString(), [$domain->team->id->toString()]);

        self::assertCount(2, $results);
        self::assertSame('203.0.113.99', $results[1]->displayLabel, 'An unidentified address is named by its address, never blanked.');
        self::assertSame(7, $results[1]->totalMessages);
        self::assertNull($results[1]->senderRole, 'No identity row means not classified — which is not the same as classified "unknown".');
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
        self::assertSame(1, $summary->needsReviewCount);
        self::assertSame(0, $summary->notAuthorizedCount);
        self::assertSame(1, $summary->unauthorizedCount());
        self::assertSame(3, $summary->uniqueIpCount);
        self::assertSame(10, $summary->needsReviewMessages, 'The CTA needs the volume the unreviewed senders carry, not just their count.');
    }

    /**
     * A sender somebody looked at and rejected is a settled decision, not a
     * pending request — it must not inflate the "waiting for your review"
     * number the call to action is built on.
     */
    public function testSummarySeparatesUnreviewedSendersFromRejectedOnes(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetTopSendersForDomain::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Tri-state Summary',
            slug: 'tri-state-summary-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'tri-state-summary.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'tri-state-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $neverReviewed = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '5.5.5.5',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 700,
            passRate: 100.0,
        );
        $em->persist($neverReviewed);

        $rejected = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '6.6.6.6',
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 40,
            passRate: 5.0,
        );
        $rejected->markUnknown($user, new \DateTimeImmutable('-2 days'));
        $em->persist($rejected);

        $em->flush();

        $summary = $query->summaryForDomain($domainId->toString(), [$team->id->toString()]);

        self::assertSame(1, $summary->needsReviewCount, 'Only the sender nobody decided about is awaiting review.');
        self::assertSame(1, $summary->notAuthorizedCount, 'The rejected sender is a decision, reported separately.');
        self::assertSame(0, $summary->authorizedCount);
        self::assertSame(
            700,
            $summary->needsReviewMessages,
            'Volume attributed to unreviewed senders must exclude the rejected one.',
        );
    }

    public function testSummaryReturnsZerosForEmptyTeamList(): void
    {
        $query = $this->getService(GetTopSendersForDomain::class);

        $summary = $query->summaryForDomain(Uuid::uuid7()->toString(), []);

        self::assertSame(0, $summary->authorizedCount);
        self::assertSame(0, $summary->needsReviewCount);
        self::assertSame(0, $summary->notAuthorizedCount);
        self::assertSame(0, $summary->uniqueIpCount);
        self::assertSame(0, $summary->needsReviewMessages);
    }

    public function testSummaryReturnsZerosForDomainWithNoSenders(): void
    {
        $query = $this->getService(GetTopSendersForDomain::class);

        $summary = $query->summaryForDomain(Uuid::uuid7()->toString(), [Uuid::uuid7()->toString()]);

        self::assertSame(0, $summary->authorizedCount);
        self::assertSame(0, $summary->needsReviewCount);
        self::assertSame(0, $summary->notAuthorizedCount);
        self::assertSame(0, $summary->uniqueIpCount);
        self::assertSame(0, $summary->needsReviewMessages);
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
        self::assertSame(0, $summary->needsReviewCount);
        self::assertSame(0, $summary->notAuthorizedCount);
        self::assertSame(0, $summary->uniqueIpCount);
        self::assertSame(0, $summary->needsReviewMessages);
    }
}
