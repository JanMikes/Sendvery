<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcReport;
use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Query\GetDomainIngestionMatrix;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\IngestionPath;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Integration coverage for the per-domain ingestion classification SQL.
 * Validates the four mutually-exclusive paths (DNS / mailbox / mixed / none),
 * cross-tenant isolation, and the "last 5 reports" sampling window — a domain
 * with 10 reports must classify off the 5 newest, not the whole history.
 */
final class GetDomainIngestionMatrixTest extends IntegrationTestCase
{
    public function testReturnsEmptyArrayForEmptyTeamList(): void
    {
        $query = $this->getService(GetDomainIngestionMatrix::class);

        self::assertSame([], $query->forTeams([]));
    }

    public function testReturnsNoneForZeroReportDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainIngestionMatrix::class);

        $team = $this->persistTeam($em);
        $domain = $this->persistDomain($em, $team);

        $rows = $query->forTeams([$team->id->toString()]);

        self::assertCount(1, $rows);
        self::assertSame($domain->id->toString(), $rows[0]->domainId);
        self::assertSame(IngestionPath::None, $rows[0]->path);
        self::assertNull($rows[0]->lastReportAt);
        self::assertNull($rows[0]->mailboxId);
    }

    public function testClassifiesDnsOnlyDomainAsDns(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainIngestionMatrix::class);

        $team = $this->persistTeam($em);
        $domain = $this->persistDomain($em, $team);

        for ($i = 0; $i < 3; ++$i) {
            $envelope = $this->persistEnvelope($em, ReportSource::CentralInbox, null, new \DateTimeImmutable('-'.$i.' days'));
            $this->persistReport($em, $domain, $envelope, new \DateTimeImmutable('-'.$i.' days'));
        }

        $rows = $query->forTeams([$team->id->toString()]);

        self::assertCount(1, $rows);
        self::assertSame(IngestionPath::Dns, $rows[0]->path);
        self::assertNotNull($rows[0]->lastReportAt);
        self::assertNull($rows[0]->mailboxId);
    }

    public function testClassifiesMailboxOnlyDomainAsMailboxAndExposesConnection(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainIngestionMatrix::class);

        $team = $this->persistTeam($em);
        $domain = $this->persistDomain($em, $team);
        $mailbox = $this->persistMailbox($em, $team);

        for ($i = 0; $i < 2; ++$i) {
            $envelope = $this->persistEnvelope($em, ReportSource::ByoMailbox, $mailbox, new \DateTimeImmutable('-'.$i.' days'));
            $this->persistReport($em, $domain, $envelope, new \DateTimeImmutable('-'.$i.' days'));
        }

        $rows = $query->forTeams([$team->id->toString()]);

        self::assertCount(1, $rows);
        self::assertSame(IngestionPath::Mailbox, $rows[0]->path);
        self::assertSame($mailbox->id->toString(), $rows[0]->mailboxId);
        self::assertSame('imap.example.com', $rows[0]->mailboxHost);
        self::assertSame(993, $rows[0]->mailboxPort);
    }

    public function testClassifiesMixedDomainAsMixed(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainIngestionMatrix::class);

        $team = $this->persistTeam($em);
        $domain = $this->persistDomain($em, $team);
        $mailbox = $this->persistMailbox($em, $team);

        $envCentral = $this->persistEnvelope($em, ReportSource::CentralInbox, null, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $domain, $envCentral, new \DateTimeImmutable('-1 day'));

        $envMailbox = $this->persistEnvelope($em, ReportSource::ByoMailbox, $mailbox, new \DateTimeImmutable('-2 days'));
        $this->persistReport($em, $domain, $envMailbox, new \DateTimeImmutable('-2 days'));

        $rows = $query->forTeams([$team->id->toString()]);

        self::assertCount(1, $rows);
        self::assertSame(IngestionPath::Mixed, $rows[0]->path);
        self::assertTrue($rows[0]->isMisconfigured());
    }

    public function testSamplingWindowLooksAtOnlyFiveMostRecentReports(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainIngestionMatrix::class);

        $team = $this->persistTeam($em);
        $domain = $this->persistDomain($em, $team);
        $mailbox = $this->persistMailbox($em, $team);

        // Five OLD reports (days 10..6) backed by a BYO mailbox — these must
        // be ignored because they fall outside the "last 5" sample window.
        for ($daysAgo = 10; $daysAgo >= 6; --$daysAgo) {
            $env = $this->persistEnvelope($em, ReportSource::ByoMailbox, $mailbox, new \DateTimeImmutable('-'.$daysAgo.' days'));
            $this->persistReport($em, $domain, $env, new \DateTimeImmutable('-'.$daysAgo.' days'));
        }

        // Five NEW reports (days 5..1) backed by the central inbox — these
        // are the only ones that should drive the classification.
        for ($daysAgo = 5; $daysAgo >= 1; --$daysAgo) {
            $env = $this->persistEnvelope($em, ReportSource::CentralInbox, null, new \DateTimeImmutable('-'.$daysAgo.' days'));
            $this->persistReport($em, $domain, $env, new \DateTimeImmutable('-'.$daysAgo.' days'));
        }

        $rows = $query->forTeams([$team->id->toString()]);

        self::assertCount(1, $rows);
        // Pure DNS — the older mailbox envelopes are outside the window.
        self::assertSame(IngestionPath::Dns, $rows[0]->path);
        self::assertNull($rows[0]->mailboxId);
    }

    public function testCrossTenantIsolation(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainIngestionMatrix::class);

        $teamA = $this->persistTeam($em);
        $teamB = $this->persistTeam($em);

        $domainA = $this->persistDomain($em, $teamA);
        $domainB = $this->persistDomain($em, $teamB);

        // Domain A: DNS-backed
        $env = $this->persistEnvelope($em, ReportSource::CentralInbox, null, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $domainA, $env, new \DateTimeImmutable('-1 day'));

        // Domain B: mailbox-backed
        $mailbox = $this->persistMailbox($em, $teamB);
        $env2 = $this->persistEnvelope($em, ReportSource::ByoMailbox, $mailbox, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $domainB, $env2, new \DateTimeImmutable('-1 day'));

        // Scoped to A only — must not see B's domain.
        $rowsA = $query->forTeams([$teamA->id->toString()]);
        self::assertCount(1, $rowsA);
        self::assertSame($domainA->id->toString(), $rowsA[0]->domainId);

        // Scoped to B only — must not see A's domain.
        $rowsB = $query->forTeams([$teamB->id->toString()]);
        self::assertCount(1, $rowsB);
        self::assertSame($domainB->id->toString(), $rowsB[0]->domainId);
    }

    private function persistTeam(EntityManagerInterface $em): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Ingestion Matrix Test',
            slug: 'mx-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function persistDomain(EntityManagerInterface $em, Team $team): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'mx-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return $domain;
    }

    private function persistMailbox(EntityManagerInterface $em, Team $team): MailboxConnection
    {
        $mailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            encryptedUsername: 'enc',
            encryptedPassword: 'enc',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $mailbox->popEvents();
        $em->persist($mailbox);
        $em->flush();

        return $mailbox;
    }

    private function persistEnvelope(
        EntityManagerInterface $em,
        ReportSource $source,
        ?MailboxConnection $mailbox,
        \DateTimeImmutable $receivedAt,
    ): ReceivedReportEmail {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: $source,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'matrix fixture',
            receivedAt: $receivedAt,
            ingestedAt: $receivedAt,
            sizeBytes: 1024,
            rawEml: 'x',
            mailboxConnection: $mailbox,
        );
        $em->persist($envelope);
        $em->flush();

        return $envelope;
    }

    private function persistReport(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        ReceivedReportEmail $envelope,
        \DateTimeImmutable $processedAt,
    ): DmarcReport {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: $processedAt,
            dateRangeEnd: $processedAt,
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: $processedAt,
            sourceEnvelope: $envelope,
        );
        $report->popEvents();
        $em->persist($report);
        $em->flush();

        return $report;
    }
}
