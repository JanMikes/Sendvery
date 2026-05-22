<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Reports;

use App\Entity\ReceivedReportEmail;
use App\Repository\ReceivedReportEmailRepository;
use App\Services\IdentityProvider;
use App\Services\Reports\CentralInboxConfig;
use App\Services\Reports\FakeCentralInboxClient;
use App\Services\Reports\ReportEmailIngestor;
use App\Tests\IntegrationTestCase;
use App\Value\MailboxEncryption;
use App\Value\Reports\CentralInboxFolder;
use App\Value\Reports\EnvelopeProcessingStatus;
use App\Value\Reports\FetchedEnvelope;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReportEmailIngestorTest extends IntegrationTestCase
{
    private ReportEmailIngestor $ingestor;
    private FakeCentralInboxClient $client;
    private ReceivedReportEmailRepository $envelopeRepository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingestor = $this->getService(ReportEmailIngestor::class);
        $this->client = $this->getService(FakeCentralInboxClient::class);
        $this->envelopeRepository = $this->getService(ReceivedReportEmailRepository::class);
        $this->em = $this->getService(EntityManagerInterface::class);

        $this->client->reset();
    }

    public function testPersistsNewEnvelopeAndMovesToPending(): void
    {
        $envelope = $this->envelope(uid: 42, messageId: '<google-001@google.com>');
        $this->client->addEnvelope($envelope);

        $persisted = $this->ingestor->ingestBatch();

        self::assertSame(1, $persisted);
        self::assertSame(
            [42 => CentralInboxFolder::Pending],
            $this->client->getMovedUids(),
        );
        self::assertTrue(
            $this->envelopeRepository->existsForSourceAndMessageId(ReportSource::CentralInbox, '<google-001@google.com>'),
        );
    }

    public function testIsIdempotentOnDuplicateMessageId(): void
    {
        $first = $this->envelope(uid: 1, messageId: '<dup-id@google.com>');
        $this->client->addEnvelope($first);
        $this->ingestor->ingestBatch();

        $duplicate = $this->envelope(uid: 99, messageId: '<dup-id@google.com>');
        $this->client->addEnvelope($duplicate);

        $persisted = $this->ingestor->ingestBatch();

        self::assertSame(0, $persisted, 'duplicate message_id is not persisted again');
        self::assertArrayHasKey(99, $this->client->getMovedUids(), 'IMAP move still happens so duplicate exits INBOX');
    }

    public function testClosesClientEvenIfWorkFails(): void
    {
        $this->client->simulateFailure('IMAP down');

        try {
            $this->ingestor->ingestBatch();
            self::fail('expected exception was not thrown');
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertSame(1, $this->client->getClosedTimes());
    }

    public function testSkipsWhenDisabled(): void
    {
        $disabled = new CentralInboxConfig(
            host: 'imap.test',
            port: 993,
            username: '',
            password: 'pass',
            encryption: MailboxEncryption::Ssl->value,
            pendingFolder: 'Sendvery/Pending',
            processedFolder: 'Sendvery/Processed',
            failedFolder: 'Sendvery/Failed',
            junkFolder: 'Sendvery/Junk',
            batchSize: 50,
            maxMessageBytes: 1_000_000,
        );

        $ingestor = new ReportEmailIngestor(
            client: $this->client,
            config: $disabled,
            envelopeRepository: $this->envelopeRepository,
            entityManager: $this->em,
            identityProvider: $this->getService(IdentityProvider::class),
            clock: $this->getService(ClockInterface::class),
            logger: new NullLogger(),
            commandBus: $this->getService(MessageBusInterface::class),
        );

        $this->client->addEnvelope($this->envelope(uid: 1, messageId: '<x@y>'));

        $persisted = $ingestor->ingestBatch();

        self::assertSame(0, $persisted);
        self::assertSame([], $this->client->getMovedUids());
    }

    public function testEmptyInboxIsANoop(): void
    {
        $persisted = $this->ingestor->ingestBatch();

        self::assertSame(0, $persisted);
        self::assertSame(1, $this->client->getClosedTimes());
    }

    public function testStoresImapUidvalidityAndUid(): void
    {
        $this->client->addEnvelope($this->envelope(uid: 17, messageId: '<m17@x>', uidvalidity: 555));

        $this->ingestor->ingestBatch();

        $this->em->clear();
        /** @var ReceivedReportEmail|null $envelope */
        $envelope = $this->em->getRepository(ReceivedReportEmail::class)
            ->findOneBy(['messageId' => '<m17@x>']);

        self::assertNotNull($envelope);
        self::assertSame(17, $envelope->imapUid);
        self::assertSame(555, $envelope->imapUidvalidity);
        // The synchronous bus runs ProcessReceivedReportEmail inline; the empty-body
        // fixture has no DMARC attachments, so the envelope ends up ignored, not pending.
        self::assertSame(EnvelopeProcessingStatus::Ignored, $envelope->processingStatus);
    }

    private function envelope(int $uid, string $messageId, ?int $uidvalidity = 1): FetchedEnvelope
    {
        return new FetchedEnvelope(
            messageId: $messageId,
            fromAddress: 'noreply-dmarc-support@google.com',
            subject: 'Report Domain: example.com',
            receivedAt: new \DateTimeImmutable('2026-05-22T08:00:00Z'),
            rawEml: "Message-ID: $messageId\r\n\r\nbody",
            uid: $uid,
            uidvalidity: $uidvalidity,
        );
    }
}
