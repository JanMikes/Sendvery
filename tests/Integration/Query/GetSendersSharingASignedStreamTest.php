<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetSendersSharingASignedStream;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * DEC-060 WP-C — cross-receiver correlation.
 */
final class GetSendersSharingASignedStreamTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;

    private GetSendersSharingASignedStream $query;

    private MonitoredDomain $domain;

    private MonitoredDomain $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->getService(EntityManagerInterface::class);
        $this->query = $this->getService(GetSendersSharingASignedStream::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Signed Streams',
            slug: 'signed-streams-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable('2026-07-01'),
        );
        $team->popEvents();
        $this->em->persist($team);

        $this->domain = $this->givenDomain($team, 'sendvery.com');
        $this->sibling = $this->givenDomain($team, 'myspeedpuzzling.com');
        $this->em->flush();
    }

    #[Test]
    public function findsTheHostsWhoseSignedStreamAlsoPassesFromAnotherAddress(): void
    {
        $report = $this->givenReport($this->domain, '2026-07-26 00:00:00');
        $this->givenRecord($report, '203.0.113.1', AuthResult::Pass, 'sendvery.com');
        $this->givenRecord($report, '203.0.113.2', AuthResult::Fail, 'sendvery.com');
        $this->givenRecord($report, '203.0.113.3', AuthResult::Fail, 'unrelated.example');
        $this->em->flush();

        $found = $this->query->forDomain(
            $this->domain->id->toString(),
            new \DateTimeImmutable('2026-07-26 00:00:00'),
            ['203.0.113.2', '203.0.113.3'],
        );

        self::assertSame(['203.0.113.2'], $found);
    }

    #[Test]
    public function comparesSigningDomainsWithoutRegardToCase(): void
    {
        $report = $this->givenReport($this->domain, '2026-07-26 00:00:00');
        $this->givenRecord($report, '203.0.113.10', AuthResult::Pass, 'SendVery.COM');
        $this->givenRecord($report, '203.0.113.11', AuthResult::Fail, 'sendvery.com');
        $this->em->flush();

        self::assertSame(
            ['203.0.113.11'],
            $this->query->forDomain($this->domain->id->toString(), new \DateTimeImmutable('2026-07-26 00:00:00'), ['203.0.113.11']),
            'Reporters spell domains however they please; DNS does not care and neither may this.',
        );
    }

    #[Test]
    public function willNotLetOneDomainsMailVouchForAnothers(): void
    {
        $ours = $this->givenReport($this->domain, '2026-07-26 00:00:00');
        $this->givenRecord($ours, '203.0.113.20', AuthResult::Fail, 'shared-vendor.example');

        $theirs = $this->givenReport($this->sibling, '2026-07-26 00:00:00');
        $this->givenRecord($theirs, '203.0.113.21', AuthResult::Pass, 'shared-vendor.example');
        $this->em->flush();

        self::assertSame(
            [],
            $this->query->forDomain($this->domain->id->toString(), new \DateTimeImmutable('2026-07-26 00:00:00'), ['203.0.113.20']),
            'The correlation is about one domain\'s own mail stream; borrowing a sibling domain\'s evidence would corroborate anything either of them ever sent.',
        );
    }

    #[Test]
    public function ignoresRecordsThatCarryNoSigningDomainAtAll(): void
    {
        $report = $this->givenReport($this->domain, '2026-07-26 00:00:00');
        $this->givenRecord($report, '203.0.113.30', AuthResult::Pass, null);
        $this->givenRecord($report, '203.0.113.31', AuthResult::Fail, null);
        $this->em->flush();

        self::assertSame(
            [],
            $this->query->forDomain($this->domain->id->toString(), new \DateTimeImmutable('2026-07-26 00:00:00'), ['203.0.113.31']),
            'Two unsigned messages have nothing in common except being unsigned.',
        );
    }

    #[Test]
    public function asksNothingOfTheDatabaseWhenThereAreNoAddressesToTest(): void
    {
        self::assertSame(
            [],
            $this->query->forDomain($this->domain->id->toString(), new \DateTimeImmutable('2026-07-26 00:00:00'), []),
        );
    }

    private function givenDomain(Team $team, string $name): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable('2026-07-01'),
        );
        $domain->popEvents();
        $this->em->persist($domain);

        return $domain;
    }

    private function givenReport(MonitoredDomain $domain, string $periodEnd): DmarcReport
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            externalReportId: 'stream-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable($periodEnd)->modify('-1 day'),
            dateRangeEnd: new \DateTimeImmutable($periodEnd),
            policyDomain: $domain->domain,
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

    private function givenRecord(DmarcReport $report, string $sourceIp, AuthResult $dkim, ?string $dkimDomain): void
    {
        $this->em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: $sourceIp,
            count: 5,
            disposition: Disposition::None,
            dkimResult: $dkim,
            spfResult: AuthResult::Fail,
            headerFrom: $report->monitoredDomain->domain,
            dkimDomain: $dkimDomain,
        ));
    }
}
