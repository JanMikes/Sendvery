<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\SenderIdentity;
use App\Entity\Team;
use App\Query\GetTopFailingSenderForTeam;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SenderRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * The single sender the pass-rate regression banner names as the dominant
 * cause of this week's failures.
 *
 * The interesting behaviour is who "the" sender is: failures spread over a
 * gateway's regional nodes belong to the gateway, not to whichever node
 * happened to be biggest.
 */
final class GetTopFailingSenderForTeamTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private GetTopFailingSenderForTeam $query;
    private Team $team;
    private MonitoredDomain $domain;
    private DmarcReport $report;

    /** Inside the query's 7-day window whatever day the suite runs. */
    private \DateTimeImmutable $recently;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recently = new \DateTimeImmutable('-1 day');
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->query = $this->getService(GetTopFailingSenderForTeam::class);

        $this->team = new Team(
            id: Uuid::uuid7(),
            name: 'Top Failing Sender',
            slug: 'top-failing-'.Uuid::uuid7()->toString(),
            createdAt: $this->recently,
        );
        $this->em->persist($this->team);

        $this->domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $this->team,
            domain: 'top-failing.example',
            createdAt: $this->recently,
        );
        $this->domain->popEvents();
        $this->em->persist($this->domain);

        $this->report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: $this->recently->modify('-1 day'),
            dateRangeEnd: $this->recently,
            policyDomain: $this->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: $this->recently,
        );
        $this->em->persist($this->report);
        $this->em->flush();
    }

    private function persistFailingRecord(string $sourceIp, int $count): void
    {
        $this->em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $this->report,
            sourceIp: $sourceIp,
            count: $count,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: $this->domain->domain,
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
            resolvedAt: $this->recently,
            hostname: $hostname,
            registrableDomain: $registrableDomain,
            organization: $organization,
            role: $role,
            resolutionAttempts: 1,
            lastAttemptAt: $this->recently,
        ));
    }

    public function testReturnsNothingWhenTheCallerCanReadNoTeams(): void
    {
        self::assertNull($this->query->forTeams([]));
    }

    public function testReturnsNothingWhenNothingFailed(): void
    {
        $this->em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $this->report,
            sourceIp: '192.0.2.1',
            count: 50,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $this->domain->domain,
        ));
        $this->em->flush();

        self::assertNull($this->query->forTeams([$this->team->id->toString()]));
    }

    public function testNamesTheSenderCarryingTheMostFailures(): void
    {
        $this->persistIdentity('192.0.2.10', 'mail.noisy.example', 'noisy.example', 'Noisy Mail', SenderRole::Esp);
        $this->persistFailingRecord('192.0.2.10', 40);
        $this->persistFailingRecord('192.0.2.20', 5);

        $sender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            sourceIp: '192.0.2.10',
            firstSeenAt: $this->recently->modify('-20 days'),
            lastSeenAt: $this->recently,
            totalMessages: 40,
            passRate: 0.0,
        );
        $this->em->persist($sender);
        $this->em->flush();

        $result = $this->query->forTeams([$this->team->id->toString()]);

        self::assertNotNull($result);
        self::assertSame('Noisy Mail', $result->displayLabel);
        self::assertSame(40, $result->failingMessageCount);
        self::assertSame('192.0.2.10', $result->sourceIp);
        self::assertSame($this->domain->id->toString(), $result->domainId);
        self::assertSame($sender->id->toString(), $result->senderId);
        self::assertSame(SenderRole::Esp, $result->senderRole);
    }

    /**
     * The banner blames one sender, so the arithmetic has to be done per
     * sender. A gateway that failed 18 messages across three continental nodes
     * outweighs a single host that failed 10 — before identity grouping, each
     * of its nodes counted 6 and the wrong sender got named.
     */
    public function testFailuresSpreadAcrossAGatewaysNodesCountAgainstTheGateway(): void
    {
        $this->persistIdentity('52.212.19.177', 'eu.cloud-sec-av.com', 'cloud-sec-av.com', null, SenderRole::Forwarder);
        $this->persistIdentity('15.222.110.90', 'ca.cloud-sec-av.com', 'cloud-sec-av.com', null, SenderRole::Forwarder);
        $this->persistIdentity('35.174.145.124', 'us.cloud-sec-av.com', 'cloud-sec-av.com', null, SenderRole::Forwarder);
        $this->persistFailingRecord('52.212.19.177', 6);
        $this->persistFailingRecord('15.222.110.90', 6);
        $this->persistFailingRecord('35.174.145.124', 6);

        $this->persistIdentity('192.0.2.30', 'mail.single.example', 'single.example', null, SenderRole::Unknown);
        $this->persistFailingRecord('192.0.2.30', 10);

        $this->em->flush();

        $result = $this->query->forTeams([$this->team->id->toString()]);

        self::assertNotNull($result);
        self::assertSame('cloud-sec-av.com', $result->displayLabel);
        self::assertSame(18, $result->failingMessageCount);
        self::assertSame(
            SenderRole::Forwarder,
            $result->senderRole,
            'Naming it a forwarder is what stops the banner reading as an accusation.',
        );
    }

    public function testAnUnidentifiedAddressCanStillBeNamedAsTheTopFailure(): void
    {
        $this->persistFailingRecord('198.51.100.42', 25);
        $this->em->flush();

        $result = $this->query->forTeams([$this->team->id->toString()]);

        self::assertNotNull($result);
        self::assertSame('198.51.100.42', $result->displayLabel);
        self::assertNull($result->senderRole);
    }

    public function testIgnoresFailuresOlderThanTheSevenDayWindow(): void
    {
        $oldReport = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-old-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-31 days'),
            dateRangeEnd: new \DateTimeImmutable('-30 days'),
            policyDomain: $this->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::Reject,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: $this->recently,
        );
        $this->em->persist($oldReport);
        $this->em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $oldReport,
            sourceIp: '198.51.100.90',
            count: 500,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: $this->domain->domain,
        ));
        $this->em->flush();

        self::assertNull($this->query->forTeams([$this->team->id->toString()]));
    }

    public function testDoesNotReturnDataForAnotherTeam(): void
    {
        $this->persistFailingRecord('198.51.100.43', 25);
        $this->em->flush();

        self::assertNull($this->query->forTeams([Uuid::uuid7()->toString()]));
    }
}
