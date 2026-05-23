<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Query\GetQuarantineList;
use App\Tests\IntegrationTestCase;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetQuarantineListTest extends IntegrationTestCase
{
    public function testCountIsZeroForFreshTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetQuarantineList::class);

        $team = $this->persistTeam($em);

        self::assertSame(0, $query->countForTeam($team->id->toString()));
        self::assertSame([], $query->forTeam($team->id->toString()));
    }

    public function testCountExcludesQuarantineRowsBelongingToOtherTeams(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetQuarantineList::class);

        $myTeam = $this->persistTeam($em);

        // Foreign team owns a monitored domain — the quarantine row attaches
        // to that domain's name, so visibility is constrained to the foreign
        // team.
        $foreignTeam = $this->persistTeam($em);
        $foreignDomainName = 'foreign-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $this->persistDomain($em, $foreignTeam, $foreignDomainName);
        $this->persistQuarantine($em, $foreignDomainName, QuarantineReason::UnverifiedDomain);

        self::assertSame(0, $query->countForTeam($myTeam->id->toString()));
    }

    public function testCountIncludesUnknownDomainRowsAfterTeamAddsMatchingDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetQuarantineList::class);

        $team = $this->persistTeam($em);
        $domainName = 'newly-added-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';

        // Simulate the timeline: quarantine row arrived first (UnknownDomain),
        // team adds the matching monitored_domain later. The query must now
        // surface the row.
        $this->persistQuarantine($em, $domainName, QuarantineReason::UnknownDomain);

        self::assertSame(0, $query->countForTeam($team->id->toString()));

        $this->persistDomain($em, $team, $domainName);

        self::assertSame(1, $query->countForTeam($team->id->toString()));
    }

    public function testCountIncludesUnknownDomainRowsReceivedViaTeamMailbox(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetQuarantineList::class);

        $team = $this->persistTeam($em);
        $mailbox = $this->persistMailbox($em, $team);
        $domainName = 'fresh-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';

        // Team has NOT added a matching monitored_domain, but the envelope was
        // pulled from their own mailbox — the new visibility rule must surface
        // the row so the "Add this domain" CTA appears.
        $this->persistQuarantine(
            $em,
            $domainName,
            QuarantineReason::UnknownDomain,
            mailboxConnection: $mailbox,
        );

        self::assertSame(1, $query->countForTeam($team->id->toString()));
        self::assertCount(1, $query->forTeam($team->id->toString()));
    }

    public function testCountExcludesUnknownDomainRowsForOtherTeamMailboxes(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetQuarantineList::class);

        $team = $this->persistTeam($em);
        $foreignTeam = $this->persistTeam($em);
        $foreignMailbox = $this->persistMailbox($em, $foreignTeam);
        $domainName = 'foreign-mb-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';

        $this->persistQuarantine(
            $em,
            $domainName,
            QuarantineReason::UnknownDomain,
            mailboxConnection: $foreignMailbox,
        );

        // Foreign team can see it via mailbox ownership; our team cannot.
        self::assertSame(1, $query->countForTeam($foreignTeam->id->toString()));
        self::assertSame(0, $query->countForTeam($team->id->toString()));
    }

    public function testCountExcludesUnknownDomainRowsWithNullMailbox(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetQuarantineList::class);

        $team = $this->persistTeam($em);
        $domainName = 'central-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';

        // Central inbox case: mailbox_connection_id is NULL. No team should
        // see this row until somebody claims the domain.
        $this->persistQuarantine(
            $em,
            $domainName,
            QuarantineReason::UnknownDomain,
            mailboxConnection: null,
        );

        self::assertSame(0, $query->countForTeam($team->id->toString()));
    }

    public function testCountExcludesUnverifiedDomainRowsEvenWhenReceivedViaTeamMailbox(): void
    {
        // The mailbox-based fallback only opens visibility for `unknown_domain`
        // rows. `unverified_domain` rows still require explicit domain ownership
        // — otherwise teams could see quarantined reports for domains they
        // don't own just because the report happened to land in one of their
        // mailboxes.
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetQuarantineList::class);

        $team = $this->persistTeam($em);
        $mailbox = $this->persistMailbox($em, $team);
        $domainName = 'unv-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';

        $this->persistQuarantine(
            $em,
            $domainName,
            QuarantineReason::UnverifiedDomain,
            mailboxConnection: $mailbox,
        );

        self::assertSame(0, $query->countForTeam($team->id->toString()));
    }

    public function testForTeamPaginatesWithLimitAndOffset(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetQuarantineList::class);

        $team = $this->persistTeam($em);
        $domainName = 'pagine-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $this->persistDomain($em, $team, $domainName);

        // Seed 3 quarantine rows for the same domain — order by quarantined_at
        // DESC means the most-recently-quarantined row is first.
        for ($i = 0; $i < 3; ++$i) {
            $this->persistQuarantine(
                $em,
                $domainName,
                QuarantineReason::UnverifiedDomain,
                quarantinedAt: new \DateTimeImmutable('-'.($i + 1).' hours'),
            );
        }

        $page1 = $query->forTeam($team->id->toString(), limit: 2, offset: 0);
        $page2 = $query->forTeam($team->id->toString(), limit: 2, offset: 2);

        self::assertCount(2, $page1);
        self::assertCount(1, $page2);
        self::assertSame(3, $query->countForTeam($team->id->toString()));
    }

    private function persistTeam(EntityManagerInterface $em): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Q Test',
            slug: 'qt-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
    }

    private function persistDomain(EntityManagerInterface $em, Team $team, string $domainName): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $domainName,
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

    private function persistQuarantine(
        EntityManagerInterface $em,
        string $domainName,
        QuarantineReason $reason,
        ?\DateTimeImmutable $quarantinedAt = null,
        ?MailboxConnection $mailboxConnection = null,
    ): QuarantinedDmarcReport {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: null === $mailboxConnection ? ReportSource::CentralInbox : ReportSource::ByoMailbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Test envelope',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 2048,
            rawEml: 'x',
            mailboxConnection: $mailboxConnection,
        );
        $em->persist($envelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: $quarantinedAt ?? new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: $reason,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);
        $em->flush();

        return $quarantine;
    }
}
