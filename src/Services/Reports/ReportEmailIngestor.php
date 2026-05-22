<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Entity\ReceivedReportEmail;
use App\Message\ProcessReceivedReportEmail;
use App\Repository\ReceivedReportEmailRepository;
use App\Services\IdentityProvider;
use App\Value\Reports\CentralInboxFolder;
use App\Value\Reports\FetchedEnvelope;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pulls one batch of envelopes from the central inbox, persists each as a
 * ReceivedReportEmail in pending state, and moves the IMAP message to
 * Sendvery/Pending so it isn't re-fetched on the next poll.
 *
 * Ordering invariant: persist BEFORE moving in IMAP. If we move first and
 * crash before persisting, the envelope is lost forever. Persist first and
 * the message stays in INBOX on retry — the unique (source, message_id)
 * constraint dedupes the second insert.
 */
final readonly class ReportEmailIngestor
{
    public function __construct(
        private CentralInboxClient $client,
        private CentralInboxConfig $config,
        private ReceivedReportEmailRepository $envelopeRepository,
        private EntityManagerInterface $entityManager,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @return int number of new envelopes persisted on this run
     */
    public function ingestBatch(): int
    {
        if (!$this->config->enabled) {
            $this->logger->info('Central reports inbox is disabled (SENDVERY_REPORTS_INBOX_ENABLED=false); skipping poll.');

            return 0;
        }

        try {
            $envelopes = $this->client->fetchPending();
            $persisted = 0;

            foreach ($envelopes as $envelope) {
                $entity = $this->persistIfNew($envelope);
                if (null !== $entity) {
                    ++$persisted;
                    $this->commandBus->dispatch(new ProcessReceivedReportEmail(envelopeId: $entity->id));
                }

                $this->client->moveToFolder($envelope->uid, CentralInboxFolder::Pending);
            }

            if (0 !== $persisted) {
                $this->logger->info('Central inbox poll persisted {count} new envelopes.', ['count' => $persisted]);
            }

            return $persisted;
        } finally {
            $this->client->close();
        }
    }

    private function persistIfNew(FetchedEnvelope $envelope): ?ReceivedReportEmail
    {
        if ($this->envelopeRepository->existsForSourceAndMessageId(ReportSource::CentralInbox, $envelope->messageId)) {
            $this->logger->debug('Duplicate envelope skipped (already ingested).', [
                'messageId' => $envelope->messageId,
            ]);

            return null;
        }

        $now = $this->clock->now();

        $entity = new ReceivedReportEmail(
            id: $this->identityProvider->nextIdentity(),
            source: ReportSource::CentralInbox,
            messageId: $envelope->messageId,
            fromAddress: $envelope->fromAddress,
            subject: $envelope->subject,
            receivedAt: $envelope->receivedAt,
            ingestedAt: $now,
            sizeBytes: strlen($envelope->rawEml),
            rawEml: $envelope->rawEml,
            imapUidvalidity: $envelope->uidvalidity,
            imapUid: $envelope->uid,
        );

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }
}
