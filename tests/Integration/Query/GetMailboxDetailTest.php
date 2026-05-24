<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcReport;
use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Query\GetMailboxDetail;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\Reports\MailboxEnvelopeStatus;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetMailboxDetailTest extends IntegrationTestCase
{
    public function testReturnsNullForUnknownMailbox(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMailboxDetail::class);

        $team = $this->persistTeam($em);

        self::assertNull($query->forMailbox(Uuid::uuid7()->toString(), [$team->id->toString()]));
    }

    public function testReturnsNullForEmptyTeamList(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMailboxDetail::class);

        $team = $this->persistTeam($em);
        $mailbox = $this->persistMailbox($em, $team);

        self::assertNull($query->forMailbox($mailbox->id->toString(), []));
    }

    public function testReturnsNullForCrossTenantMailbox(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMailboxDetail::class);

        $ownerTeam = $this->persistTeam($em);
        $intruderTeam = $this->persistTeam($em);
        $mailbox = $this->persistMailbox($em, $ownerTeam);

        self::assertNull(
            $query->forMailbox($mailbox->id->toString(), [$intruderTeam->id->toString()]),
        );
    }

    public function testReturnsMailboxMetadataAndZeroCountsWhenEmpty(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMailboxDetail::class);

        $team = $this->persistTeam($em);
        $mailbox = $this->persistMailbox($em, $team);

        $result = $query->forMailbox($mailbox->id->toString(), [$team->id->toString()]);

        self::assertNotNull($result);
        self::assertSame($mailbox->id->toString(), $result->mailboxId);
        self::assertSame($team->id->toString(), $result->teamId);
        self::assertSame('imap.example.com', $result->host);
        self::assertSame(993, $result->port);
        self::assertSame(0, $result->envelopesTotal);
        self::assertSame(0, $result->envelopes30d);
        self::assertSame(0, $result->envelopes7d);
        self::assertSame(0, $result->reportsParsed);
        self::assertSame(0, $result->envelopesQuarantined);
    }

    public function testStatCountsForRecentEnvelopesParsedAndQuarantined(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMailboxDetail::class);

        $team = $this->persistTeam($em);
        $mailbox = $this->persistMailbox($em, $team);
        $domain = $this->persistDomain($em, $team, 'mb-stats-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test');

        // 1 envelope @ 1d ago, parsed -> counts in 7d, 30d, total + reports_parsed
        $env1 = $this->persistEnvelope($em, $mailbox, new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $domain, $env1);

        // 1 envelope @ 10d ago, quarantined -> counts in 30d, total + quarantined
        $env2 = $this->persistEnvelope($em, $mailbox, new \DateTimeImmutable('-10 days'));
        $this->persistQuarantine($em, $env2, $domain->domain);

        // 1 envelope @ 60d ago, unparsed -> counts in total only
        $this->persistEnvelope($em, $mailbox, new \DateTimeImmutable('-60 days'));

        $result = $query->forMailbox($mailbox->id->toString(), [$team->id->toString()]);

        self::assertNotNull($result);
        self::assertSame(3, $result->envelopesTotal);
        self::assertSame(2, $result->envelopes30d);
        self::assertSame(1, $result->envelopes7d);
        self::assertSame(1, $result->reportsParsed);
        self::assertSame(1, $result->envelopesQuarantined);
    }

    public function testRecentEnvelopesReturnsNewestFirstWithStatus(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMailboxDetail::class);

        $team = $this->persistTeam($em);
        $mailbox = $this->persistMailbox($em, $team);
        $domain = $this->persistDomain($em, $team, 'recent-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test');

        $envOld = $this->persistEnvelope($em, $mailbox, new \DateTimeImmutable('-3 days'));
        $envParsed = $this->persistEnvelope($em, $mailbox, new \DateTimeImmutable('-2 days'));
        $report = $this->persistReport($em, $domain, $envParsed);
        $envQuarantined = $this->persistEnvelope($em, $mailbox, new \DateTimeImmutable('-1 day'));
        $quarantine = $this->persistQuarantine($em, $envQuarantined, $domain->domain);

        $envelopes = $query->recentEnvelopesForMailbox($mailbox->id->toString());

        self::assertCount(3, $envelopes);

        // newest first
        self::assertSame($envQuarantined->id->toString(), $envelopes[0]->envelopeId);
        self::assertSame(MailboxEnvelopeStatus::Quarantined, $envelopes[0]->status);
        self::assertSame($quarantine->id->toString(), $envelopes[0]->targetReportId);

        self::assertSame($envParsed->id->toString(), $envelopes[1]->envelopeId);
        self::assertSame(MailboxEnvelopeStatus::Parsed, $envelopes[1]->status);
        self::assertSame($report->id->toString(), $envelopes[1]->targetReportId);

        self::assertSame($envOld->id->toString(), $envelopes[2]->envelopeId);
        self::assertSame(MailboxEnvelopeStatus::Unparsed, $envelopes[2]->status);
        self::assertNull($envelopes[2]->targetReportId);
    }

    public function testRecentEnvelopesRespectsLimit(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMailboxDetail::class);

        $team = $this->persistTeam($em);
        $mailbox = $this->persistMailbox($em, $team);

        for ($i = 0; $i < 25; ++$i) {
            $this->persistEnvelope($em, $mailbox, new \DateTimeImmutable('-'.$i.' hours'));
        }

        self::assertCount(20, $query->recentEnvelopesForMailbox($mailbox->id->toString()));
        self::assertCount(5, $query->recentEnvelopesForMailbox($mailbox->id->toString(), 5));
    }

    public function testSummaryForMailboxesReturnsEmptyArrayForNoIds(): void
    {
        $query = $this->getService(GetMailboxDetail::class);

        self::assertSame([], $query->summaryForMailboxes([]));
    }

    public function testSummaryForMailboxesAggregatesPerMailboxOver30d(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMailboxDetail::class);

        $team = $this->persistTeam($em);
        $domain = $this->persistDomain($em, $team, 'sum-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test');

        $mailboxA = $this->persistMailbox($em, $team);
        $mailboxB = $this->persistMailbox($em, $team);

        // mailbox A: 2 envelopes in 30d (1 parsed), 1 envelope 40d ago (excluded)
        $envA1 = $this->persistEnvelope($em, $mailboxA, new \DateTimeImmutable('-2 days'));
        $this->persistReport($em, $domain, $envA1);
        $this->persistEnvelope($em, $mailboxA, new \DateTimeImmutable('-15 days'));
        $this->persistEnvelope($em, $mailboxA, new \DateTimeImmutable('-40 days'));

        // mailbox B: 1 envelope in 30d (quarantined)
        $envB1 = $this->persistEnvelope($em, $mailboxB, new \DateTimeImmutable('-5 days'));
        $this->persistQuarantine($em, $envB1, $domain->domain);

        $summary = $query->summaryForMailboxes([
            $mailboxA->id->toString(),
            $mailboxB->id->toString(),
        ]);

        self::assertArrayHasKey($mailboxA->id->toString(), $summary);
        self::assertSame(2, $summary[$mailboxA->id->toString()]->envelopes30d);
        self::assertSame(1, $summary[$mailboxA->id->toString()]->reports30d);
        self::assertSame(0, $summary[$mailboxA->id->toString()]->quarantined30d);

        self::assertArrayHasKey($mailboxB->id->toString(), $summary);
        self::assertSame(1, $summary[$mailboxB->id->toString()]->envelopes30d);
        self::assertSame(0, $summary[$mailboxB->id->toString()]->reports30d);
        self::assertSame(1, $summary[$mailboxB->id->toString()]->quarantined30d);
    }

    private function persistTeam(EntityManagerInterface $em): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'MB Detail Test',
            slug: 'mb-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
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

    private function persistDomain(EntityManagerInterface $em, Team $team, string $name): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return $domain;
    }

    private function persistEnvelope(
        EntityManagerInterface $em,
        MailboxConnection $mailbox,
        \DateTimeImmutable $receivedAt,
    ): ReceivedReportEmail {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::ByoMailbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'DMARC report',
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
    ): DmarcReport {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-1 day'),
            dateRangeEnd: new \DateTimeImmutable(),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
            sourceEnvelope: $envelope,
        );
        $report->popEvents();
        $em->persist($report);
        $em->flush();

        return $report;
    }

    private function persistQuarantine(
        EntityManagerInterface $em,
        ReceivedReportEmail $envelope,
        string $domainName,
    ): QuarantinedDmarcReport {
        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('-1 day'),
            dateRangeEnd: new \DateTimeImmutable(),
            quarantinedAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: QuarantineReason::UnverifiedDomain,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);
        $em->flush();

        return $quarantine;
    }
}
