<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DmarcReport;
use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\PollMailbox;
use App\MessageHandler\PollMailboxHandler;
use App\Services\Mail\FakeMailClient;
use App\Tests\IntegrationTestCase;
use App\Value\MailAttachment;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\MailMessage;
use App\Value\SubscriptionPlan;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * The monthly report cap must not depend on which pipeline a report arrived
 * through. The BYO-mailbox poller used to dispatch without asking while the
 * usage counter incremented anyway, so the same team was capped on the central
 * inbox and uncapped on its own mailbox.
 *
 * Over-cap mail is left in the user's mailbox rather than parked in quarantine
 * (a quarantine row needs a central-inbox envelope), which is also why nothing
 * is lost: the next poll after an upgrade or a period roll picks it up.
 */
final class PollMailboxRespectsPlanCapTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private FakeMailClient $fakeClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->fakeClient = $this->getService(FakeMailClient::class);
        $this->fakeClient->reset();
    }

    #[Test]
    public function aTeamAtItsCapDoesNotGetMoreReportsParsedJustBecauseTheyArrivedByMailbox(): void
    {
        $domain = $this->pollWithUsage(reportsParsed: 100, messageId: '<over-cap@example.com>');

        self::assertSame(
            [],
            $this->em->getRepository(DmarcReport::class)->findBy(['monitoredDomain' => $domain->id->toString()]),
            'Parsing this would count against a cap that is already full — the cap has to bite on every ingestion path, not just the central inbox.',
        );
    }

    #[Test]
    public function anOverCapMailboxMessageIsLeftUnprocessedSoTheReportIsNotLost(): void
    {
        $this->pollWithUsage(reportsParsed: 100, messageId: '<left-behind@example.com>');

        self::assertSame(
            [],
            $this->fakeClient->getProcessedMessageIds(),
            'The user\'s mailbox is the durable copy: leaving the message unflagged is what makes the next poll after an upgrade or period roll pick it up instead of losing it.',
        );
    }

    #[Test]
    public function aTeamWithHeadroomIsUnaffected(): void
    {
        $domain = $this->pollWithUsage(reportsParsed: 0, messageId: '<within-cap@example.com>');

        self::assertCount(
            1,
            $this->em->getRepository(DmarcReport::class)->findBy(['monitoredDomain' => $domain->id->toString()]),
        );
        self::assertSame(['<within-cap@example.com>'], $this->fakeClient->getProcessedMessageIds());
    }

    private function pollWithUsage(int $reportsParsed, string $messageId): MonitoredDomain
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Cap Poll',
            slug: 'cap-poll-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: SubscriptionPlan::Free->value,
        );
        $team->popEvents();
        $this->em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'cap-poll-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $this->em->persist($domain);

        $connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.test.com',
            port: 993,
            encryptedUsername: 'enc-user',
            encryptedPassword: 'enc-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            monitoredDomain: $domain,
        );
        $connection->popEvents();
        $this->em->persist($connection);
        $this->em->flush();

        $this->insertUsage($team->id, $reportsParsed);

        $xml = file_get_contents(__DIR__.'/../../Fixtures/minimal-report.xml');
        assert(is_string($xml));

        $this->fakeClient->addMessage(new MailMessage(
            messageId: $messageId,
            subject: 'DMARC Report',
            from: 'noreply-dmarc-support@google.com',
            date: new \DateTimeImmutable(),
            attachments: [new MailAttachment('report.xml', $xml, 'text/xml')],
            rawEml: "Message-ID: raw\r\nSubject: raw\r\n\r\nbody",
        ));

        $this->getService(PollMailboxHandler::class)(new PollMailbox(connectionId: $connection->id));
        $this->em->flush();
        $this->em->clear();

        return $domain;
    }

    private function insertUsage(UuidInterface $teamId, int $reportsParsed): void
    {
        $periodStart = $this->getService(ClockInterface::class)->now()
            ->modify('first day of this month')
            ->setTime(0, 0);

        $this->getService(Connection::class)->executeStatement(
            'INSERT INTO team_usage (team_id, reports_parsed_count, period_started_at, period_ends_at)
             VALUES (:teamId, :count, :startsAt, :endsAt)',
            [
                'teamId' => $teamId->toString(),
                'count' => $reportsParsed,
                'startsAt' => $periodStart->format('Y-m-d H:i:s'),
                'endsAt' => $periodStart->modify('+1 month')->format('Y-m-d H:i:s'),
            ],
        );
    }
}
