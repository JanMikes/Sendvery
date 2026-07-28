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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * A `Message-ID` is a header written by whoever sent the mail. It is unique to
 * a message, not to a tenant, and nothing stops two tenants receiving the same
 * message.
 *
 * The envelope ledger deduped BYO mail on `(source, message_id)` alone, and
 * `ReportSource::ByoMailbox` is the same value for every customer on the
 * platform — so that pair is a key shared across all of them. The headerless
 * fallback id in the same method was already namespaced per connection
 * (`<no-message-id-{connectionId}-{sha256}@…>`), which is the tell: the code
 * knew the key needed a per-connection namespace and omitted it on the one
 * path where the value is supplied by a stranger.
 *
 * The realistic trigger needs no attacker at all. A domain publishing
 * `rua=mailto:reports@acme.example,mailto:dmarc@agency.example` — the owner and
 * their agency — has both addresses receiving the same reporter message. Each
 * side connects its own inbox on its own team. One Message-ID, two tenants, and
 * the second poll silently binds to the first tenant's envelope.
 */
final class MailboxEnvelopesAreScopedToTheirOwnMailboxTest extends IntegrationTestCase
{
    private const string SHARED_MESSAGE_ID = '<CAF=shared-reporter-message@mail.google.com>';

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
    public function oneReporterMessageSentToTwoTeamsBecomesOneEnvelopeEach(): void
    {
        $owner = $this->teamWithMailbox('rua-owner');
        $agency = $this->teamWithMailbox('rua-agency');

        $this->pollWith($owner, $this->reporterMessage(self::SHARED_MESSAGE_ID));
        $this->pollWith($agency, $this->reporterMessage(self::SHARED_MESSAGE_ID));

        self::assertCount(
            1,
            $this->envelopesFor($owner['connection']),
            'The domain owner received this reporter message in their own inbox and it must be recorded against their own mailbox.',
        );
        self::assertCount(
            1,
            $this->envelopesFor($agency['connection']),
            'So did the agency. Deduping on a header the sender chose, across every tenant on the platform, makes the second team\'s poll bind to the first team\'s row.',
        );

        $detail = $this->getService(GetMailboxDetail::class);

        $agencyDetail = $detail->forMailbox($agency['connection']->id->toString(), [$agency['team']->id->toString()]);
        self::assertNotNull($agencyDetail);
        self::assertSame(1, $agencyDetail->envelopesTotal, 'A mailbox that received a report and shows zero is the "my mailbox is broken" reading this ledger exists to prevent.');
        self::assertSame(1, $agencyDetail->reportsParsed);

        $ownerDetail = $detail->forMailbox($owner['connection']->id->toString(), [$owner['team']->id->toString()]);
        self::assertNotNull($ownerDetail);
        self::assertSame(1, $ownerDetail->envelopesTotal, 'And the owner\'s counts must not have been inflated by the agency\'s delivery.');
        self::assertSame(1, $ownerDetail->reportsParsed);
    }

    #[Test]
    public function onePollCannotRewriteAnotherTeamsEnvelope(): void
    {
        $victim = $this->teamWithMailbox('victim');
        $stranger = $this->teamWithMailbox('stranger');

        $this->pollWith($victim, $this->reporterMessage(self::SHARED_MESSAGE_ID));

        $before = $this->envelopesFor($victim['connection'])[0];
        $beforeAttempts = $before->attempts;
        self::assertSame(EnvelopeProcessingStatus::Parsed, $before->processingStatus);

        // The stranger's mailbox holds a mail claiming the same Message-ID, and
        // this copy yields no report — so the shared row would be re-stamped
        // with a failure belonging to someone else's mailbox entirely.
        $this->pollWith($stranger, new MailMessage(
            messageId: self::SHARED_MESSAGE_ID,
            subject: 'DMARC Aggregate Report',
            from: 'noreply-dmarc-support@google.com',
            date: new \DateTimeImmutable('2026-07-20 09:00:00'),
            attachments: [new MailAttachment('report.xml.gz', 'not actually gzip', 'application/gzip')],
            rawEml: 'Message-ID: '.self::SHARED_MESSAGE_ID."\r\n\r\nstranger copy",
        ));

        $after = $this->envelopesFor($victim['connection'])[0];
        self::assertTrue($before->id->equals($after->id));
        self::assertSame(EnvelopeProcessingStatus::Parsed, $after->processingStatus, 'Another tenant\'s poll must not restate the outcome of ours.');
        self::assertNull($after->processingError, 'Nor attach their failure to our row.');
        self::assertSame($beforeAttempts, $after->attempts, 'Nor count their attempts as ours.');
        self::assertStringEndsWith(
            'stranger copy',
            $this->envelopesFor($stranger['connection'])[0]->rawEmlBytes(),
            'The stranger gets their own row holding their own bytes, not a pointer into ours.',
        );
        self::assertStringEndsWith('reporter copy', $after->rawEmlBytes(), 'And ours still holds what we actually received.');
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

    /**
     * @param array{team: Team, connection: MailboxConnection} $tenant
     */
    private function pollWith(array $tenant, MailMessage $message): void
    {
        $this->fakeClient->reset();
        $this->fakeClient->addMessage($message);
        $this->getService(PollMailboxHandler::class)(new PollMailbox(connectionId: $tenant['connection']->id));
        $this->em->flush();
    }

    private function reporterMessage(string $messageId): MailMessage
    {
        $xml = file_get_contents(__DIR__.'/../../Fixtures/minimal-report.xml');
        \assert(is_string($xml));

        return new MailMessage(
            messageId: $messageId,
            subject: 'Report Domain: example.com',
            from: 'noreply-dmarc-support@google.com',
            date: new \DateTimeImmutable('2026-07-20 09:00:00'),
            attachments: [new MailAttachment('report.xml', $xml, 'text/xml')],
            rawEml: "Message-ID: {$messageId}\r\n\r\nreporter copy",
        );
    }

    /** @return array{team: Team, connection: MailboxConnection} */
    private function teamWithMailbox(string $label): array
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Tenant '.$label,
            slug: $label.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $this->em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $label.'-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $this->em->persist($domain);

        $connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: 'imap.'.$label.'.test',
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

        return ['team' => $team, 'connection' => $connection];
    }
}
