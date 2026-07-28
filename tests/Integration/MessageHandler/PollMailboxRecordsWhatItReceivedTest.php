<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Message\PollMailbox;
use App\MessageHandler\PollMailboxHandler;
use App\Query\GetMailboxDetail;
use App\Services\Mail\FakeMailClient;
use App\Tests\IntegrationTestCase;
use App\Value\MailAttachment;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\MailMessage;
use App\Value\Reports\EnvelopeProcessingStatus;
use App\Value\Reports\ReportSource;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * A BYO mailbox left no trace of what it had received.
 *
 * `received_report_email` is the ledger every ingestion surface reads: the
 * mailbox detail page's envelope counts, its recent-deliveries list, the
 * reports list's "arrived via this mailbox" filter, and the ingestion matrix
 * that tells a domain whether it is fed by DNS, by a mailbox, or by both. Only
 * the central inbox ever wrote to it, so a BYO mailbox that had been happily
 * ingesting reports for months displayed zeroes across all of them — the one
 * reading a user is most likely to interpret as "my mailbox is broken".
 *
 * The reports themselves were fine. It is the record of them that was missing.
 */
final class PollMailboxRecordsWhatItReceivedTest extends IntegrationTestCase
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
    public function aPolledMailboxRecordsTheEmailItIngested(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team);
        $connection = $this->createConnection($team, $domain);
        $this->em->flush();

        $rawEml = $this->rawEml('<byo-envelope-1@example.com>');
        $this->fakeClient->addMessage($this->dmarcMessage('<byo-envelope-1@example.com>', $rawEml));

        $this->poll($connection);

        $envelopes = $this->envelopesFor($connection);
        self::assertCount(1, $envelopes, 'Every mail a BYO mailbox hands us is an envelope, exactly as it is on the central inbox.');

        $envelope = $envelopes[0];
        self::assertSame(ReportSource::ByoMailbox, $envelope->source);
        self::assertNotNull($envelope->mailboxConnection);
        self::assertTrue($connection->id->equals($envelope->mailboxConnection->id), 'Without the mailbox link every per-mailbox count and filter stays empty.');
        self::assertSame('<byo-envelope-1@example.com>', $envelope->messageId);
        self::assertSame('DMARC Aggregate Report', $envelope->subject);
        self::assertSame('noreply-dmarc-support@google.com', $envelope->fromAddress);
        self::assertSame($rawEml, $envelope->rawEmlBytes(), 'The original bytes are what makes a failed parse re-runnable later.');
        self::assertSame(\strlen($rawEml), $envelope->sizeBytes);
        self::assertSame(EnvelopeProcessingStatus::Parsed, $envelope->processingStatus);
    }

    #[Test]
    public function theMailboxDetailPageStopsReportingZeroDeliveries(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team);
        $connection = $this->createConnection($team, $domain);
        $this->em->flush();

        $this->fakeClient->addMessage($this->dmarcMessage('<byo-counts@example.com>', $this->rawEml('<byo-counts@example.com>')));

        $this->poll($connection);

        $detail = $this->getService(GetMailboxDetail::class)->forMailbox($connection->id->toString(), [$team->id->toString()]);
        self::assertNotNull($detail);
        self::assertSame(1, $detail->envelopesTotal, 'A working mailbox showing "0 emails received" reads as a broken mailbox.');
        self::assertSame(1, $detail->envelopes30d);

        $recent = $this->getService(GetMailboxDetail::class)->recentEnvelopesForMailbox($connection->id->toString());
        self::assertCount(1, $recent, 'The "recent deliveries" list on the mailbox page is fed by the same ledger.');
        self::assertSame('DMARC Aggregate Report', $recent[0]->subject);
    }

    #[Test]
    public function eachStoredReportSaysWhichEmailItArrivedIn(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team);
        $connection = $this->createConnection($team, $domain);
        $this->em->flush();

        $this->fakeClient->addMessage($this->dmarcMessage('<byo-linked@example.com>', $this->rawEml('<byo-linked@example.com>')));

        $this->poll($connection);

        $envelope = $this->envelopesFor($connection)[0];

        $linked = $this->getService(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM dmarc_report WHERE source_envelope_id = :envelopeId',
            ['envelopeId' => $envelope->id->toString()],
        );
        self::assertSame(1, (int) $linked, 'Without this link the reports list cannot filter by the mailbox a report arrived through.');

        $detail = $this->getService(GetMailboxDetail::class)->forMailbox($connection->id->toString(), [$team->id->toString()]);
        self::assertNotNull($detail);
        self::assertSame(1, $detail->reportsParsed);
    }

    #[Test]
    public function pollingTheSameMailTwiceRecordsItOnce(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team);
        $connection = $this->createConnection($team, $domain);
        $this->em->flush();

        $message = $this->dmarcMessage('<byo-twice@example.com>', $this->rawEml('<byo-twice@example.com>'));

        $this->fakeClient->addMessage($message);
        $this->poll($connection);

        // A BYO mailbox is polled every 15 minutes and a message it failed to
        // flag stays unseen, so re-reading the same mail is the normal case,
        // not the exception.
        $this->fakeClient->reset();
        $this->fakeClient->addMessage($message);
        $this->poll($connection);

        self::assertCount(1, $this->envelopesFor($connection), 'Re-reading an unflagged mail must not multiply the delivery count the user sees.');
    }

    #[Test]
    public function anEmailWeCouldNotFileAgainstADomainIsStillRecorded(): void
    {
        $team = $this->createTeam();
        // No monitored domain on the connection: nothing to file the report under.
        $connection = $this->createConnection($team, null);
        $this->em->flush();

        $this->fakeClient->addMessage($this->dmarcMessage('<byo-nodomain@example.com>', $this->rawEml('<byo-nodomain@example.com>')));

        $this->poll($connection);

        $envelopes = $this->envelopesFor($connection);
        self::assertCount(1, $envelopes, 'The mail arrived. Refusing to record it is how "we received nothing" and "we could not use what we received" became the same screen.');
        self::assertSame(EnvelopeProcessingStatus::Ignored, $envelopes[0]->processingStatus);
        self::assertNotNull($envelopes[0]->processingError, 'The envelope has to say why it went nowhere.');
    }

    #[Test]
    public function anEmailHeldBackByThePlanCapIsRecordedAsWaitingRatherThanAsHeldByUs(): void
    {
        $team = $this->createTeam();
        $team->plan = 'free';
        $domain = $this->createDomain($team);
        $connection = $this->createConnection($team, $domain);
        $this->em->flush();
        $this->fillThisMonthsAllowance($team->id->toString());

        $this->fakeClient->addMessage($this->dmarcMessage('<byo-overcap@example.com>', $this->rawEml('<byo-overcap@example.com>')));

        $this->poll($connection);

        $envelopes = $this->envelopesFor($connection);
        self::assertCount(1, $envelopes, 'A capped mailbox still received the mail; the record of it is what tells the user their mailbox is working.');
        self::assertSame(EnvelopeProcessingStatus::Ignored, $envelopes[0]->processingStatus);
        self::assertNotSame(
            EnvelopeProcessingStatus::Quarantined,
            $envelopes[0]->processingStatus,
            'Quarantined would claim we are holding the only copy. We are not — it is still sitting unread in the customer\'s own mailbox.',
        );
        self::assertSame(
            [],
            $this->fakeClient->getProcessedMessageIds(),
            'Recording the envelope must not start flagging over-cap mail read: the unflagged mail is what makes the next poll after an upgrade pick it up.',
        );
    }

    #[Test]
    public function twoCopiesOfOneMailInASinglePollDoNotDestroyTheWholePoll(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team);
        $connection = $this->createConnection($team, $domain);
        $this->em->flush();

        // A good report first. It dispatches, and dispatching is what flushes —
        // and what flags the mail \Seen on the IMAP server. That flag is NOT
        // transactional and IMAP is only ever read with ->unseen(), so if this
        // poll later rolls back, this report is gone from the database and
        // unreachable in the mailbox. Permanently.
        $this->fakeClient->addMessage($this->dmarcMessage('<byo-survivor@example.com>', $this->rawEml('<byo-survivor@example.com>')));

        // Then two copies of one mail that yields no report at all. Nothing
        // dispatches, so nothing flushes between them, so a dedupe that only
        // asks SQL cannot see the copy recorded a moment earlier and both rows
        // race the unique index on (source, message_id).
        $this->fakeClient->addMessage($this->undeliverableMessage('<byo-duplicated@example.com>'));
        $this->fakeClient->addMessage($this->undeliverableMessage('<byo-duplicated@example.com>'));

        $this->poll($connection);

        self::assertCount(
            2,
            $this->envelopesFor($connection),
            'Two distinct mails, one of which arrived twice: two envelopes. Aborting the transaction instead loses the OTHER mails in the batch, whose IMAP \Seen flags the rollback cannot undo.',
        );

        $survivors = $this->getService(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM dmarc_report dr
             JOIN received_report_email e ON e.id = dr.source_envelope_id
             WHERE e.mailbox_connection_id = :connectionId',
            ['connectionId' => $connection->id->toString()],
        );
        self::assertSame(1, (int) $survivors, 'The report we successfully parsed before the duplicate pair must still be there.');
    }

    #[Test]
    public function anEmailTooLargeToStoreIsRefusedRatherThanSwallowedWhole(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team);
        $connection = $this->createConnection($team, $domain);
        $this->em->flush();

        // SENDVERY_REPORTS_INBOX_MAX_MESSAGE_BYTES is 10485760 under .env.test —
        // the same ceiling the central inbox refuses oversized mail at. Both
        // paths write whole message bytes into `raw_eml`, so both need it.
        $oversized = str_repeat('x', 10_485_761);

        $this->fakeClient->addMessage(new MailMessage(
            messageId: '<byo-oversized@example.com>',
            subject: 'DMARC Aggregate Report',
            from: 'noreply-dmarc-support@google.com',
            date: new \DateTimeImmutable('2026-07-20 09:00:00'),
            attachments: [new MailAttachment('report.xml', (string) file_get_contents(__DIR__.'/../../Fixtures/minimal-report.xml'), 'text/xml')],
            rawEml: $oversized,
        ));

        $this->poll($connection);

        $envelopes = $this->envelopesFor($connection);
        self::assertCount(1, $envelopes, 'The mail arrived, so it is on the ledger — refusing it is not the same as pretending it never came.');
        // Compared by length: a failing assertion on the bytes themselves would
        // dump ten megabytes of 'x' into the test output.
        self::assertSame(0, \strlen($envelopes[0]->rawEmlBytes()), 'Storing unbounded customer mail is a data-retention surface we do not want and did not previously have.');
        self::assertSame(\strlen($oversized), $envelopes[0]->sizeBytes, 'The true size is what makes the refusal legible from the row itself.');
        self::assertSame(EnvelopeProcessingStatus::Failed, $envelopes[0]->processingStatus);
        self::assertStringContainsString('over the 10485760-byte limit', (string) $envelopes[0]->processingError);
        self::assertSame(
            [],
            $this->fakeClient->getProcessedMessageIds(),
            'Never flag a mail read that we declined to take: the customer\'s mailbox is now the only copy of it.',
        );
    }

    #[Test]
    public function twoEmailsWithNoMessageIdHeaderAreRecordedSeparately(): void
    {
        $team = $this->createTeam();
        $domain = $this->createDomain($team);
        $connection = $this->createConnection($team, $domain);
        $this->em->flush();

        // `uniq_envelope_source_msgid` is a UNIQUE index over (source, message_id).
        // Without a derived id these two share the empty string, and because
        // both of them parse — and parsing flushes — the dedupe query sees the
        // first row and hands the second mail the first mail's envelope. The
        // outcome is not a crash but a SILENT MERGE: one delivery shown where
        // two arrived, and the second mail's reports filed under the first
        // mail's provenance. (The constraint violation is the other branch of
        // the same bug, reached only when nothing in the batch parses; that
        // case is covered above.)
        $this->fakeClient->addMessage($this->dmarcMessage('', $this->rawEml('<first>')));
        $this->fakeClient->addMessage($this->dmarcMessage('', $this->rawEml('<second>')));

        $this->poll($connection);

        self::assertCount(2, $this->envelopesFor($connection), 'Two distinct mails are two deliveries even when neither carries a Message-ID.');
    }

    /** 100 is the Free plan's monthly report cap (PlanLimits::getMaxReportsPerMonth). */
    private function fillThisMonthsAllowance(string $teamId): void
    {
        $periodStart = $this->getService(ClockInterface::class)->now()->modify('first day of this month')->setTime(0, 0);

        $this->getService(Connection::class)->executeStatement(
            'INSERT INTO team_usage (team_id, reports_parsed_count, period_started_at, period_ends_at)
             VALUES (:teamId, 100, :startsAt, :endsAt)',
            [
                'teamId' => $teamId,
                'startsAt' => $periodStart->format('Y-m-d H:i:s'),
                'endsAt' => $periodStart->modify('+1 month')->format('Y-m-d H:i:s'),
            ],
        );
    }

    /** @return list<ReceivedReportEmail> */
    private function envelopesFor(MailboxConnection $connection): array
    {
        $this->em->clear();

        /** @var list<ReceivedReportEmail> $envelopes */
        $envelopes = $this->em->getRepository(ReceivedReportEmail::class)
            ->createQueryBuilder('e')
            ->where('e.mailboxConnection = :connection')
            ->setParameter('connection', $connection->id->toString())
            ->orderBy('e.ingestedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $envelopes;
    }

    private function poll(MailboxConnection $connection): void
    {
        $this->getService(PollMailboxHandler::class)(new PollMailbox(connectionId: $connection->id));
        $this->em->flush();
    }

    private function dmarcMessage(string $messageId, string $rawEml): MailMessage
    {
        $xml = file_get_contents(__DIR__.'/../../Fixtures/minimal-report.xml');
        \assert(is_string($xml));

        return new MailMessage(
            messageId: $messageId,
            subject: 'DMARC Aggregate Report',
            from: 'noreply-dmarc-support@google.com',
            date: new \DateTimeImmutable('2026-07-20 09:00:00'),
            attachments: [new MailAttachment('report.xml', $xml, 'text/xml')],
            rawEml: $rawEml,
        );
    }

    /**
     * A mail whose attachment yields nothing — a truncated or renamed archive,
     * which is what a half-finished download from a reporter looks like. It
     * matters here because it dispatches no report, and therefore triggers no
     * flush on its way out.
     */
    private function undeliverableMessage(string $messageId): MailMessage
    {
        return new MailMessage(
            messageId: $messageId,
            subject: 'DMARC Aggregate Report',
            from: 'noreply-dmarc-support@google.com',
            date: new \DateTimeImmutable('2026-07-20 09:00:00'),
            attachments: [new MailAttachment('report.xml.gz', 'not actually gzip', 'application/gzip')],
            rawEml: $this->rawEml($messageId),
        );
    }

    private function rawEml(string $messageId): string
    {
        return "Message-ID: {$messageId}\r\nSubject: DMARC Aggregate Report\r\n\r\nbody";
    }

    private function createTeam(): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'BYO Envelope',
            slug: 'byo-envelope-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $this->em->persist($team);

        return $team;
    }

    private function createDomain(Team $team): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'byo-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $this->em->persist($domain);

        return $domain;
    }

    private function createConnection(Team $team, ?MonitoredDomain $monitoredDomain): MailboxConnection
    {
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
            monitoredDomain: $monitoredDomain,
        );
        $connection->popEvents();
        $this->em->persist($connection);

        return $connection;
    }
}
