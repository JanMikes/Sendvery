<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessReceivedReportEmail;
use App\Message\ReprocessQuarantinedReport;
use App\Repository\QuarantinedDmarcReportRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ReprocessQuarantinedReportHandler
{
    public function __construct(
        private QuarantinedDmarcReportRepository $quarantineRepository,
        private MessageBusInterface $commandBus,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    public function __invoke(ReprocessQuarantinedReport $message): void
    {
        $quarantined = $this->quarantineRepository->find($message->quarantineId);

        if (null === $quarantined) {
            // Already released (e.g. by the verify-then-release listener) or
            // purged by retention — nothing to do.
            return;
        }

        // Resolve the envelope ID via raw DBAL rather than the entity proxy
        // (`$quarantined->receivedEmail->id`). Touching the proxy's `id`
        // would force Doctrine to keep the envelope in the identity map after
        // we remove the quarantine row, and the downstream
        // ProcessReceivedReportEmailHandler later calls `EntityManager::find`
        // on that same id — which then collides with the lingering ghost
        // proxy and trips Doctrine's readonly-id guard when it tries to
        // re-initialise. Pulling the FK as a primitive string keeps the
        // identity map untouched and lets the downstream handler hydrate the
        // envelope from scratch.
        /** @var string|false $envelopeIdString */
        $envelopeIdString = $this->connection->fetchOne(
            'SELECT received_email_id FROM quarantined_dmarc_report WHERE id = :id',
            ['id' => $message->quarantineId->toString()],
        );
        assert(is_string($envelopeIdString));

        // Detach the still-attached envelope proxy *before* we remove the
        // quarantine row. The quarantine's `receivedEmail` association is
        // hydrated as an uninitialised LAZY_GHOST proxy by `find()`; if we
        // leave it in the identity map, the downstream
        // ProcessReceivedReportEmailHandler later calls `EntityManager::find`
        // on that envelope id, hits the lingering ghost, triggers
        // initialisation — and Doctrine's lazy-init path on an entity with a
        // `readonly` `$id` then trips the "Attempting to change readonly
        // property … $id" guard. Detaching forces the downstream handler to
        // hydrate the envelope from scratch.
        $this->entityManager->detach($quarantined->receivedEmail);

        $this->entityManager->remove($quarantined);

        $this->commandBus->dispatch(new ProcessReceivedReportEmail(
            envelopeId: Uuid::fromString($envelopeIdString),
        ));
    }
}
