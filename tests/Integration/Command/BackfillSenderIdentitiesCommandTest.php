<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Repository\SenderIdentityRepository;
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
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @see docs/16-sender-identity-and-digest-truthfulness.md (DEC-059 D1, WP2)
 */
final class BackfillSenderIdentitiesCommandTest extends IntegrationTestCase
{
    use ScriptsDnsRecords;

    private EntityManagerInterface $em;

    private Connection $database;

    private MonitoredDomain $domain;

    private DmarcReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->getService(EntityManagerInterface::class);
        $this->database = $this->getService(Connection::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Backfill',
            slug: 'backfill-'.Uuid::uuid7()->toString(),
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

        $this->report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            externalReportId: 'backfill-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('2026-07-02 00:00:00'),
            dateRangeEnd: new \DateTimeImmutable('2026-07-03 23:59:59'),
            policyDomain: 'sendvery.com',
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable('2026-07-04 06:00:00'),
        );
        $this->report->popEvents();
        $this->em->persist($this->report);
    }

    public function testNamesTheSendersOnReportsThatWereIngestedBeforeWeIdentifiedThem(): void
    {
        $this->scriptReverseDns()
            ->withHostname('77.75.76.89', 'mxb.seznam.cz')
            ->withHostname('52.212.19.177', 'eu.cloud-sec-av.com');

        $this->givenRecord('77.75.76.89');
        $this->givenRecord('52.212.19.177');
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['mxb.seznam.cz', 'Seznam'], $this->enrichmentOf('77.75.76.89'));
        self::assertSame(['eu.cloud-sec-av.com', null], $this->enrichmentOf('52.212.19.177'));
        self::assertStringContainsString('Identified 2 of 2 address(es)', $tester->getDisplay());
    }

    public function testRemembersEachSenderSoLaterReportsCostNothing(): void
    {
        $this->scriptReverseDns()->withHostname('40.93.13.60', 'mail-dm2.outbound.protection.outlook.com');

        $this->givenRecord('40.93.13.60');
        $this->em->flush();

        $this->tester()->execute([]);
        $this->em->clear();

        $identity = $this->getService(SenderIdentityRepository::class)->findByIp('40.93.13.60');

        self::assertNotNull($identity);
        self::assertSame('outlook.com', $identity->registrableDomain);
        self::assertSame(
            SenderRole::Forwarder,
            $identity->role,
            'Microsoft 365 relaying a message onward is forwarding, and the backfill must classify it as such.',
        );
    }

    public function testCanBeRunAgainWithoutChangingAnything(): void
    {
        $reverseDns = $this->scriptReverseDns();
        $reverseDns->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $this->givenRecord('77.75.76.89');
        $this->em->flush();

        $this->tester()->execute([]);
        $secondRun = $this->tester();
        $secondRun->execute([]);

        self::assertSame(['mxb.seznam.cz', 'Seznam'], $this->enrichmentOf('77.75.76.89'));
        self::assertStringContainsString('Every DMARC record already names its sending host.', $secondRun->getDisplay());
        self::assertSame(1, $reverseDns->lookupCount(), 'A second pass must not pay for the same lookup twice.');
    }

    public function testLeavesAnAlreadyNamedSenderAlone(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $this->givenRecord('77.75.76.89', resolvedHostname: 'relay.example.com', resolvedOrg: 'Named by hand');
        $this->em->flush();

        $this->tester()->execute([]);

        self::assertSame(
            ['relay.example.com', 'Named by hand'],
            $this->enrichmentOf('77.75.76.89'),
            'The backfill fills gaps; it never rewrites enrichment that is already there.',
        );
    }

    public function testWorksThroughABacklogInBitesWhenAskedTo(): void
    {
        $reverseDns = $this->scriptReverseDns();

        for ($i = 1; $i <= 4; ++$i) {
            $ip = '198.51.100.'.$i;
            $reverseDns->withHostname($ip, sprintf('host%d.partner.example', $i));
            $this->givenRecord($ip);
        }

        $this->em->flush();

        $this->tester()->execute(['--limit' => '2']);

        $named = (int) $this->database->fetchOne(
            'SELECT COUNT(*) FROM dmarc_record WHERE dmarc_report_id = :reportId AND resolved_hostname IS NOT NULL',
            ['reportId' => $this->report->id->toString()],
        );

        self::assertSame(2, $named, 'A large backlog must be workable in bounded runs.');
        self::assertSame(2, $reverseDns->lookupCount());
    }

    public function testPrefersAddressesItHasNeverLookedUp(): void
    {
        $reverseDns = $this->scriptReverseDns();

        // An address with no reverse record stays unnamed forever; if it kept
        // claiming the run's budget, the addresses that would actually resolve
        // would never get their turn.
        $this->givenRecord('198.51.100.1');
        $this->givenRecord('198.51.100.9');
        $reverseDns->withHostname('198.51.100.9', 'late.partner.example');
        $this->em->flush();

        $this->tester()->execute(['--limit' => '1']);
        $this->tester()->execute(['--limit' => '1']);

        self::assertSame(
            ['late.partner.example', null],
            $this->enrichmentOf('198.51.100.9'),
            'An address that has already been looked up must not starve the ones that have not.',
        );
    }

    public function testShowsWhatItWouldLearnWithoutWritingAnything(): void
    {
        $this->scriptReverseDns()->withHostname('77.75.76.89', 'mxb.seznam.cz');

        $this->givenRecord('77.75.76.89');
        $this->givenRecord('77.75.76.89');
        $this->em->flush();

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);
        $this->em->clear();

        self::assertSame([null, null], $this->enrichmentOf('77.75.76.89'));
        self::assertNull($this->getService(SenderIdentityRepository::class)->findByIp('77.75.76.89'));
        self::assertStringContainsString('Dry run, nothing written', $tester->getDisplay());
        self::assertStringContainsString('and name 2', $tester->getDisplay());
    }

    public function testSaysSoWhenThereIsNothingToBackfill(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Every DMARC record already names its sending host.', $tester->getDisplay());
    }

    public function testRefusesAMeaninglessBatchSize(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--limit' => '0']));
        self::assertStringContainsString('must be a positive number', $tester->getDisplay());
    }

    private function givenRecord(
        string $sourceIp,
        ?string $resolvedHostname = null,
        ?string $resolvedOrg = null,
    ): void {
        $this->em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $this->report,
            sourceIp: $sourceIp,
            count: 3,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: 'sendvery.com',
            resolvedHostname: $resolvedHostname,
            resolvedOrg: $resolvedOrg,
        ));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function enrichmentOf(string $sourceIp): array
    {
        $row = $this->database->fetchAssociative(
            'SELECT resolved_hostname, resolved_org FROM dmarc_record WHERE dmarc_report_id = :reportId AND source_ip = :sourceIp',
            ['reportId' => $this->report->id->toString(), 'sourceIp' => $sourceIp],
        );

        self::assertIsArray($row);

        return [
            null === $row['resolved_hostname'] ? null : (string) $row['resolved_hostname'],
            null === $row['resolved_org'] ? null : (string) $row['resolved_org'],
        ];
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:senders:backfill-identities'));
    }
}
