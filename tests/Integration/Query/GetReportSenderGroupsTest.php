<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\SenderIdentity;
use App\Entity\Team;
use App\Query\GetReportSenderGroups;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SenderRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Coverage for the GROUP BY-driven sender aggregation feeding the
 * "By sender" pane on report-detail. Each test builds a fresh team /
 * domain / report so DAMA can roll back independently.
 */
final class GetReportSenderGroupsTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private GetReportSenderGroups $query;
    private Team $team;
    private UuidInterface $teamId;
    private MonitoredDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->query = $this->getService(GetReportSenderGroups::class);

        $this->teamId = Uuid::uuid7();
        $this->team = new Team(
            id: $this->teamId,
            name: 'Sender Groups Test',
            slug: 'sender-groups-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($this->team);

        $this->domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $this->team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $this->domain->popEvents();
        $this->em->persist($this->domain);
        $this->em->flush();
    }

    private function createReport(): DmarcReport
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('2026-05-01'),
            dateRangeEnd: new \DateTimeImmutable('2026-05-02'),
            policyDomain: $this->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
        );
        $this->em->persist($report);

        return $report;
    }

    private function persistRecord(
        DmarcReport $report,
        string $sourceIp,
        int $count,
        AuthResult $dkim,
        AuthResult $spf,
        Disposition $disposition = Disposition::None,
        ?string $resolvedHostname = null,
        ?string $resolvedOrg = null,
    ): void {
        $this->em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: $sourceIp,
            count: $count,
            disposition: $disposition,
            dkimResult: $dkim,
            spfResult: $spf,
            headerFrom: $this->domain->domain,
            resolvedHostname: $resolvedHostname,
            resolvedOrg: $resolvedOrg,
        ));
    }

    private function persistIdentity(
        string $sourceIp,
        string $hostname,
        string $registrableDomain,
        ?string $organization,
        SenderRole $role,
    ): void {
        $this->em->persist(new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: $sourceIp,
            resolvedAt: new \DateTimeImmutable('2026-05-02'),
            hostname: $hostname,
            registrableDomain: $registrableDomain,
            organization: $organization,
            role: $role,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable('2026-05-02'),
        ));
    }

    private function persistKnownSender(string $sourceIp, bool $isAuthorized): void
    {
        $now = new \DateTimeImmutable();
        $this->em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            sourceIp: $sourceIp,
            firstSeenAt: $now,
            lastSeenAt: $now,
            totalMessages: 0,
            passRate: 0.0,
            isAuthorized: $isAuthorized,
        ));
    }

    public function testReturnsEmptyWhenTeamIdsEmpty(): void
    {
        $report = $this->createReport();
        $this->persistRecord($report, '1.2.3.4', 10, AuthResult::Pass, AuthResult::Pass);
        $this->em->flush();

        self::assertSame([], $this->query->forReport($report->id->toString(), []));
    }

    public function testReturnsEmptyForNonExistentReport(): void
    {
        $result = $this->query->forReport(Uuid::uuid7()->toString(), [$this->teamId->toString()]);

        self::assertSame([], $result);
    }

    public function testSingleRecordSingleGroup(): void
    {
        $report = $this->createReport();
        $this->persistRecord(
            $report,
            '1.2.3.4',
            42,
            AuthResult::Pass,
            AuthResult::Pass,
            resolvedHostname: 'mail.example.net',
            resolvedOrg: 'Example Org',
        );
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertSame('Example Org', $groups[0]->displayLabel);
        self::assertSame(42, $groups[0]->totalMessages);
        self::assertSame(['1.2.3.4'], $groups[0]->sourceIps);
        self::assertNull($groups[0]->senderIsAuthorized);
    }

    public function testGroupsByResolvedOrg(): void
    {
        $report = $this->createReport();
        // Two IPs, same org → one group of 30.
        $this->persistRecord($report, '1.1.1.1', 10, AuthResult::Pass, AuthResult::Pass, resolvedOrg: 'google.com');
        $this->persistRecord($report, '1.1.1.2', 20, AuthResult::Pass, AuthResult::Fail, resolvedOrg: 'google.com');
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertSame('google.com', $groups[0]->displayLabel);
        self::assertSame(30, $groups[0]->totalMessages);
        self::assertSame(30, $groups[0]->dkimPassCount);
        self::assertSame(10, $groups[0]->spfPassCount);
        $sourceIps = $groups[0]->sourceIps;
        sort($sourceIps);
        self::assertSame(['1.1.1.1', '1.1.1.2'], $sourceIps);
    }

    public function testGroupsByHostnameFallbackWhenOrgMissing(): void
    {
        $report = $this->createReport();
        $this->persistRecord($report, '2.2.2.2', 5, AuthResult::Pass, AuthResult::Pass, resolvedHostname: 'mta.mailgun.org');
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertSame('mta.mailgun.org', $groups[0]->displayLabel);
    }

    public function testGroupsByIpFallbackWhenOrgAndHostnameMissing(): void
    {
        $report = $this->createReport();
        $this->persistRecord($report, '3.3.3.3', 7, AuthResult::Pass, AuthResult::Pass);
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertSame('3.3.3.3', $groups[0]->displayLabel);
        self::assertSame(['3.3.3.3'], $groups[0]->sourceIps);
    }

    public function testSeparatesDifferentOrgs(): void
    {
        $report = $this->createReport();
        $this->persistRecord($report, '1.1.1.1', 30, AuthResult::Pass, AuthResult::Pass, resolvedOrg: 'google.com');
        $this->persistRecord($report, '2.2.2.2', 10, AuthResult::Pass, AuthResult::Pass, resolvedOrg: 'mailchimp.com');
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(2, $groups);
        // Ordered by total_messages DESC → google first.
        self::assertSame('google.com', $groups[0]->displayLabel);
        self::assertSame(30, $groups[0]->totalMessages);
        self::assertSame('mailchimp.com', $groups[1]->displayLabel);
        self::assertSame(10, $groups[1]->totalMessages);
    }

    public function testAggregatesDkimPassRate(): void
    {
        $report = $this->createReport();
        // 13 pass + 5 fail = 18 total → 72.2%
        $this->persistRecord($report, '1.1.1.1', 13, AuthResult::Pass, AuthResult::Fail, resolvedOrg: 'sender.com');
        $this->persistRecord($report, '1.1.1.2', 5, AuthResult::Fail, AuthResult::Fail, resolvedOrg: 'sender.com');
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertSame(18, $groups[0]->totalMessages);
        self::assertSame(13, $groups[0]->dkimPassCount);
        self::assertSame(72.2, $groups[0]->dkimPassRate);
    }

    public function testAggregatesDispositionCounts(): void
    {
        $report = $this->createReport();
        $this->persistRecord($report, '1.1.1.1', 5, AuthResult::Pass, AuthResult::Pass, Disposition::None, resolvedOrg: 'sender.com');
        $this->persistRecord($report, '1.1.1.2', 3, AuthResult::Fail, AuthResult::Fail, Disposition::Quarantine, resolvedOrg: 'sender.com');
        $this->persistRecord($report, '1.1.1.3', 2, AuthResult::Fail, AuthResult::Fail, Disposition::Reject, resolvedOrg: 'sender.com');
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertSame(5, $groups[0]->dispositionNone);
        self::assertSame(3, $groups[0]->dispositionQuarantine);
        self::assertSame(2, $groups[0]->dispositionReject);
    }

    public function testSenderIsAuthorizedTrueWhenKnownSenderAuthorized(): void
    {
        $report = $this->createReport();
        $this->persistRecord($report, '9.9.9.9', 10, AuthResult::Pass, AuthResult::Pass, resolvedOrg: 'mailchimp.com');
        $this->persistKnownSender('9.9.9.9', isAuthorized: true);
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertTrue($groups[0]->senderIsAuthorized);
    }

    public function testSenderIsAuthorizedFalseWhenKnownSenderUnauthorized(): void
    {
        $report = $this->createReport();
        $this->persistRecord($report, '8.8.8.8', 10, AuthResult::Pass, AuthResult::Pass, resolvedOrg: 'unknown.com');
        $this->persistKnownSender('8.8.8.8', isAuthorized: false);
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertFalse($groups[0]->senderIsAuthorized);
    }

    /**
     * The pane exists to answer "who sent this and did it work". Splitting one
     * gateway across its regional nodes answered it three times, each answer
     * too small to act on — and none of those nodes appears in any curated
     * organisation list, so only the PTR's registrable domain can join them up.
     */
    public function testAGatewaysRegionalNodesAreOneGroupCarryingEveryAddress(): void
    {
        $report = $this->createReport();

        $this->persistIdentity('52.212.19.177', 'eu.cloud-sec-av.com', 'cloud-sec-av.com', null, SenderRole::Forwarder);
        $this->persistIdentity('15.222.110.90', 'ca.cloud-sec-av.com', 'cloud-sec-av.com', null, SenderRole::Forwarder);
        $this->persistIdentity('35.174.145.124', 'us.cloud-sec-av.com', 'cloud-sec-av.com', null, SenderRole::Forwarder);

        $this->persistRecord($report, '52.212.19.177', 2, AuthResult::Pass, AuthResult::Fail);
        $this->persistRecord($report, '15.222.110.90', 1, AuthResult::Fail, AuthResult::Fail);
        $this->persistRecord($report, '35.174.145.124', 1, AuthResult::Pass, AuthResult::Fail);
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertSame('cloud-sec-av.com', $groups[0]->displayLabel);
        self::assertSame(4, $groups[0]->totalMessages);
        self::assertSame(SenderRole::Forwarder, $groups[0]->senderRole);

        $sourceIps = $groups[0]->sourceIps;
        sort($sourceIps);
        self::assertSame(
            ['15.222.110.90', '35.174.145.124', '52.212.19.177'],
            $sourceIps,
            'Collapsing the display must not hide which addresses are behind it — the evidence table still expands from these.',
        );
    }

    public function testARotatingRelayPoolIsOneGroupUnderItsOrganisationName(): void
    {
        $report = $this->createReport();

        $this->persistIdentity('2a02:598:2222::1', 'mxb-1-908.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp);
        $this->persistIdentity('2a02:598:2222::2', 'mxb-2-904.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp);
        $this->persistIdentity('2a02:598:2222::3', 'mxb-3-514.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp);

        $this->persistRecord($report, '2a02:598:2222::1', 5, AuthResult::Pass, AuthResult::Pass);
        $this->persistRecord($report, '2a02:598:2222::2', 5, AuthResult::Pass, AuthResult::Pass);
        $this->persistRecord($report, '2a02:598:2222::3', 5, AuthResult::Pass, AuthResult::Pass);
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertSame('Seznam', $groups[0]->displayLabel);
        self::assertSame(15, $groups[0]->totalMessages);
    }

    /**
     * A group is allowed to be part-identified: resolution is bounded per
     * batch, so the second node of a pool can still be waiting. The classified
     * member's role speaks for the group, because "one of these is a gateway"
     * is the fact that explains the failures.
     */
    public function testAPartlyClassifiedGroupStillReportsTheRoleItIsKnownToHave(): void
    {
        $report = $this->createReport();

        $this->persistIdentity('3.132.108.44', 'ipw-outbound.inkyphishfence.com', 'inkyphishfence.com', null, SenderRole::Forwarder);
        $this->persistIdentity('34.210.15.192', 'ipw-outbound.inkyphishfence.com', 'inkyphishfence.com', null, SenderRole::Unknown);

        $this->persistRecord($report, '3.132.108.44', 1, AuthResult::Pass, AuthResult::Fail);
        $this->persistRecord($report, '34.210.15.192', 1, AuthResult::Pass, AuthResult::Fail);
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertSame(SenderRole::Forwarder, $groups[0]->senderRole);
    }

    public function testAnAddressWithNoIdentityKeepsItsPlaceAndReportsNoRole(): void
    {
        $report = $this->createReport();

        $this->persistIdentity('198.51.100.4', 'mail.identified.example', 'identified.example', null, SenderRole::Esp);
        $this->persistRecord($report, '198.51.100.4', 9, AuthResult::Pass, AuthResult::Pass);
        $this->persistRecord($report, '198.51.100.77', 3, AuthResult::Fail, AuthResult::Fail);
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(2, $groups);
        self::assertSame('198.51.100.77', $groups[1]->displayLabel);
        self::assertNull($groups[1]->senderRole);
    }

    /**
     * The identity cache is authoritative over the enrichment copied onto each
     * record at ingest: records written before enrichment existed, or replayed
     * after a purge, carry nothing, and the shared cache is what fills them in.
     */
    public function testTheIdentityCacheNamesSendersWhoseRecordsWereIngestedWithoutEnrichment(): void
    {
        $report = $this->createReport();

        $this->persistIdentity('192.0.2.55', 'mta.mailgun.org', 'mailgun.org', 'Mailgun', SenderRole::Esp);
        $this->persistRecord($report, '192.0.2.55', 12, AuthResult::Pass, AuthResult::Pass);
        $this->em->flush();

        $groups = $this->query->forReport($report->id->toString(), [$this->teamId->toString()]);

        self::assertCount(1, $groups);
        self::assertSame('Mailgun', $groups[0]->displayLabel);
    }

    public function testCrossTenantIsolation(): void
    {
        $report = $this->createReport();
        $this->persistRecord($report, '1.2.3.4', 10, AuthResult::Pass, AuthResult::Pass);
        $this->em->flush();

        // Query with a DIFFERENT team's id → must return nothing despite
        // a valid reportId, because md.team_id IN (:teamIds) blocks it.
        $otherTeamId = Uuid::uuid7()->toString();
        $result = $this->query->forReport($report->id->toString(), [$otherTeamId]);

        self::assertSame([], $result);
    }
}
