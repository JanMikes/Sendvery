<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Repository\SenderIdentityRepository;
use App\Services\SenderDiscovery;
use App\Tests\IntegrationTestCase;
use App\Tests\ScriptsDnsRecords;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SenderRole;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * @see docs/16-sender-identity-and-digest-truthfulness.md (DEC-059 D1, D7)
 */
final class SenderDiscoveryTest extends IntegrationTestCase
{
    use ScriptsDnsRecords;

    private EntityManagerInterface $em;

    private Connection $database;

    private MonitoredDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->getService(EntityManagerInterface::class);
        $this->database = $this->getService(Connection::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Sender Discovery',
            slug: 'sender-discovery-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable('2026-07-01'),
        );
        $team->popEvents();
        $this->em->persist($team);

        $this->domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'sendvery.com',
            createdAt: new \DateTimeImmutable('2026-07-01'),
        );
        $this->domain->popEvents();
        $this->em->persist($this->domain);
        $this->em->flush();
    }

    public function testNamesTheSendingHostOnTheReportsOwnRecords(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $report = $this->givenReport('2026-07-03 23:59:59');
        $this->givenRecord($report, '77.75.76.89', count: 40, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->em->flush();

        $this->discover($report->id);

        self::assertSame(
            ['mxb.seznam.cz', 'Seznam'],
            $this->enrichmentOf($report->id, '77.75.76.89'),
            'The dashboard and the digest read the sender name off dmarc_record; leaving it null is what made them render raw IPs.',
        );
    }

    public function testNamesAGatewayNobodyHasMappedByItsHostname(): void
    {
        $this->scriptReverseDns()
            ->withHostname('52.212.19.177', 'eu.cloud-sec-av.com')
            ->withHostname('3.132.108.44', 'ipw-outbound.inkyphishfence.com');

        $report = $this->givenReport('2026-07-20 23:59:59');
        $this->givenRecord($report, '52.212.19.177', count: 1, dkim: AuthResult::Pass, spf: AuthResult::Fail);
        $this->givenRecord($report, '3.132.108.44', count: 2, dkim: AuthResult::Fail, spf: AuthResult::Fail);
        $this->em->flush();

        $this->discover($report->id);

        self::assertSame(
            ['eu.cloud-sec-av.com', null],
            $this->enrichmentOf($report->id, '52.212.19.177'),
            'A host with no curated organisation name still has a hostname worth showing instead of an IP.',
        );
        self::assertSame(
            ['ipw-outbound.inkyphishfence.com', null],
            $this->enrichmentOf($report->id, '3.132.108.44'),
        );
    }

    public function testEnrichesEveryRecordSharingTheSameSendingHost(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $report = $this->givenReport('2026-07-03 23:59:59');
        $this->givenRecord($report, '77.75.76.89', count: 10, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->givenRecord($report, '77.75.76.89', count: 5, dkim: AuthResult::Pass, spf: AuthResult::Fail, headerFrom: 'mail.sendvery.com');
        $this->em->flush();

        $this->discover($report->id);

        $names = $this->database->fetchFirstColumn(
            'SELECT resolved_org FROM dmarc_record WHERE dmarc_report_id = :reportId',
            ['reportId' => $report->id->toString()],
        );

        self::assertSame(['Seznam', 'Seznam'], $names);
    }

    public function testLooksUpEachSendingHostOncePerReport(): void
    {
        $reverseDns = $this->scriptReverseDns();
        $reverseDns
            ->withHostname('2a02:598:1::1', 'mxb-1-a01.seznam.cz')
            ->withHostname('2a02:598:2::9', 'mxb-2-904.seznam.cz');

        $report = $this->givenReport('2026-07-10 23:59:59');
        $this->givenRecord($report, '2a02:598:1::1', count: 3, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->givenRecord($report, '2a02:598:1::1', count: 4, dkim: AuthResult::Pass, spf: AuthResult::Pass, headerFrom: 'mail.sendvery.com');
        $this->givenRecord($report, '2a02:598:2::9', count: 2, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->em->flush();

        $this->discover($report->id);

        self::assertSame(
            2,
            $reverseDns->lookupCount(),
            'Reverse DNS runs inside a worker, so a report resolves its distinct addresses in one batch — never once per record.',
        );
    }

    public function testRecordsWhenASenderWasActiveRatherThanWhenItsReportArrived(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.78.89', 'mxb.seznam.cz');

        $report = $this->givenReport('2026-07-03 23:59:59');
        $this->givenRecord($report, '77.75.78.89', count: 12, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->em->flush();

        $this->discover($report->id);
        $this->em->flush();

        $sender = $this->knownSenderRow('77.75.78.89');

        self::assertSame('2026-07-03 23:59:59', $sender['first_seen_at']);
        self::assertSame(
            '2026-07-03 23:59:59',
            $sender['last_seen_at'],
            'Stamping the ingest time told the owner a three-week-old sender was brand new.',
        );
    }

    public function testMovesFirstSeenBackwardsWhenAnOlderReportArrivesLate(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.78.89', 'mxb.seznam.cz');

        $recent = $this->givenReport('2026-07-26 23:59:59');
        $this->givenRecord($recent, '77.75.78.89', count: 5, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->em->flush();
        $this->discover($recent->id);
        $this->em->flush();

        $late = $this->givenReport('2026-07-03 23:59:59');
        $this->givenRecord($late, '77.75.78.89', count: 7, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->em->flush();
        $this->discover($late->id);
        $this->em->flush();

        $sender = $this->knownSenderRow('77.75.78.89');

        self::assertSame(
            '2026-07-03 23:59:59',
            $sender['first_seen_at'],
            'Reports arrive out of order, so a late one proves the sender was active earlier than we thought.',
        );
        self::assertSame(
            '2026-07-26 23:59:59',
            $sender['last_seen_at'],
            'A late report must not make an active sender look dormant.',
        );
        self::assertSame(12, (int) $sender['total_messages']);
    }

    public function testAveragesThePassRateAcrossEverythingASenderHasSent(): void
    {
        $this->scriptReverseDns()->withHostname('198.51.100.20', 'relay.partner.example');

        $first = $this->givenReport('2026-07-05 23:59:59');
        $this->givenRecord($first, '198.51.100.20', count: 10, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->em->flush();
        $this->discover($first->id);
        $this->em->flush();

        $second = $this->givenReport('2026-07-06 23:59:59');
        $this->givenRecord($second, '198.51.100.20', count: 10, dkim: AuthResult::Fail, spf: AuthResult::Fail);
        $this->em->flush();
        $this->discover($second->id);
        $this->em->flush();

        $sender = $this->knownSenderRow('198.51.100.20');

        self::assertSame(20, (int) $sender['total_messages']);
        self::assertSame(50.0, (float) $sender['pass_rate']);
    }

    public function testNeverOverwritesWhatTheOperatorDecidedAboutASender(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $sender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            sourceIp: '77.75.76.89',
            firstSeenAt: new \DateTimeImmutable('2026-07-01 00:00:00'),
            lastSeenAt: new \DateTimeImmutable('2026-07-01 00:00:00'),
            totalMessages: 4,
            passRate: 100.0,
            hostname: 'our-relay.internal',
            organization: 'Our own relay',
            label: 'Primary outbound',
            isAuthorized: true,
        );
        $this->em->persist($sender);

        $report = $this->givenReport('2026-07-08 23:59:59');
        $this->givenRecord($report, '77.75.76.89', count: 6, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->em->flush();

        $this->discover($report->id);
        $this->em->flush();

        $reloaded = $this->knownSenderRow('77.75.76.89');

        self::assertSame(1, (int) $reloaded['is_authorized'], 'Authorization is the operator\'s judgement and ingest has no business revising it.');
        self::assertSame('Primary outbound', $reloaded['label']);
        self::assertSame('our-relay.internal', $reloaded['hostname'], 'A name the operator chose outranks a PTR record.');
        self::assertSame('Our own relay', $reloaded['organization']);
        self::assertSame(10, (int) $reloaded['total_messages']);
    }

    public function testFillsInTheSenderDetailsThatAreStillMissing(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $this->em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            sourceIp: '77.75.76.89',
            firstSeenAt: new \DateTimeImmutable('2026-07-01 00:00:00'),
            lastSeenAt: new \DateTimeImmutable('2026-07-01 00:00:00'),
            totalMessages: 4,
            passRate: 100.0,
        ));

        $report = $this->givenReport('2026-07-08 23:59:59');
        $this->givenRecord($report, '77.75.76.89', count: 6, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->em->flush();

        $this->discover($report->id);
        $this->em->flush();

        $reloaded = $this->knownSenderRow('77.75.76.89');

        self::assertSame('mxb.seznam.cz', $reloaded['hostname']);
        self::assertSame('Seznam', $reloaded['organization']);
    }

    public function testLeavesAnAddressWithNoReverseRecordAsAnAddress(): void
    {
        $report = $this->givenReport('2026-07-12 23:59:59');
        $this->givenRecord($report, '198.51.100.77', count: 3, dkim: AuthResult::Fail, spf: AuthResult::Fail);
        $this->em->flush();

        $this->discover($report->id);
        $this->em->flush();

        self::assertSame(
            [null, null],
            $this->enrichmentOf($report->id, '198.51.100.77'),
            'There is nothing honest to show but the address itself.',
        );
        self::assertNull($this->knownSenderRow('198.51.100.77')['hostname']);
    }

    public function testKeepsOneTeamsTrustOutOfTheSharedIdentityCache(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $this->em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            sourceIp: '77.75.76.89',
            firstSeenAt: new \DateTimeImmutable('2026-07-01 00:00:00'),
            lastSeenAt: new \DateTimeImmutable('2026-07-01 00:00:00'),
            totalMessages: 4,
            passRate: 100.0,
            isAuthorized: true,
        ));

        $report = $this->givenReport('2026-07-08 23:59:59');
        $this->givenRecord($report, '77.75.76.89', count: 6, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->em->flush();

        $this->discover($report->id);
        $this->em->flush();

        $identity = $this->getService(SenderIdentityRepository::class)->findByIp('77.75.76.89');

        self::assertNotNull($identity);
        self::assertSame(
            SenderRole::Esp,
            $identity->role,
            'One team vouching for an address must not present it as everybody\'s own relay.',
        );
    }

    public function testDoesNotInventAPassRateForASenderThatCarriedNoMessages(): void
    {
        $this->scriptReverseDns()->withHostname('198.51.100.30', 'idle.partner.example');

        $report = $this->givenReport('2026-07-18 23:59:59');
        $this->givenRecord($report, '198.51.100.30', count: 0, dkim: AuthResult::Fail, spf: AuthResult::Fail);
        $this->em->flush();

        $this->discover($report->id);
        $this->em->flush();

        $sender = $this->knownSenderRow('198.51.100.30');

        self::assertSame(0, (int) $sender['total_messages']);
        self::assertSame(0.0, (float) $sender['pass_rate']);
    }

    public function testHasNothingToDiscoverFromAReportThatNoLongerExists(): void
    {
        $this->discover(Uuid::uuid7());

        self::assertSame(0, $this->countKnownSenders());
    }

    public function testHasNothingToDiscoverFromAReportWithoutRecords(): void
    {
        $report = $this->givenReport('2026-07-15 23:59:59');
        $this->em->flush();

        $this->discover($report->id);
        $this->em->flush();

        self::assertSame(0, $this->countKnownSenders());
    }

    private function discover(UuidInterface $reportId): void
    {
        $this->getService(SenderDiscovery::class)->updateFromReport($this->domain, $reportId);
    }

    private function givenReport(string $periodEnd): DmarcReport
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            externalReportId: 'discovery-'.Uuid::uuid7()->toString(),
            dateRangeBegin: (new \DateTimeImmutable($periodEnd))->modify('-1 day'),
            dateRangeEnd: new \DateTimeImmutable($periodEnd),
            policyDomain: $this->domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable('2026-07-27 06:00:00'),
        );
        $report->popEvents();
        $this->em->persist($report);

        return $report;
    }

    private function givenRecord(
        DmarcReport $report,
        string $sourceIp,
        int $count,
        AuthResult $dkim,
        AuthResult $spf,
        string $headerFrom = 'sendvery.com',
    ): void {
        $this->em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: $sourceIp,
            count: $count,
            disposition: Disposition::None,
            dkimResult: $dkim,
            spfResult: $spf,
            headerFrom: $headerFrom,
        ));
    }

    /**
     * @return array{0: ?string, 1: ?string} hostname and organisation as stored on the report's records
     */
    private function enrichmentOf(UuidInterface $reportId, string $sourceIp): array
    {
        $row = $this->database->fetchAssociative(
            'SELECT resolved_hostname, resolved_org FROM dmarc_record WHERE dmarc_report_id = :reportId AND source_ip = :sourceIp',
            ['reportId' => $reportId->toString(), 'sourceIp' => $sourceIp],
        );

        self::assertIsArray($row);

        return [
            null === $row['resolved_hostname'] ? null : (string) $row['resolved_hostname'],
            null === $row['resolved_org'] ? null : (string) $row['resolved_org'],
        ];
    }

    /**
     * Read back through SQL rather than the entity: ingest widens the
     * first/last-seen window with a set-based UPDATE, so the in-memory entity is
     * deliberately not the source of truth here.
     *
     * @return array<string, mixed>
     */
    private function knownSenderRow(string $sourceIp): array
    {
        $row = $this->database->fetchAssociative(
            'SELECT first_seen_at, last_seen_at, total_messages, pass_rate, hostname, organization, label,
                is_authorized::int AS is_authorized
            FROM known_sender
            WHERE monitored_domain_id = :domainId AND source_ip = :sourceIp',
            ['domainId' => $this->domain->id->toString(), 'sourceIp' => $sourceIp],
        );

        self::assertIsArray($row, sprintf('Expected the ingest to have recorded a sender for %s.', $sourceIp));

        return $row;
    }

    private function countKnownSenders(): int
    {
        return (int) $this->database->fetchOne(
            'SELECT COUNT(*) FROM known_sender WHERE monitored_domain_id = :domainId',
            ['domainId' => $this->domain->id->toString()],
        );
    }
}
