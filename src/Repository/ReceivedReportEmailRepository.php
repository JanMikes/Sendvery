<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MailboxConnection;
use App\Entity\ReceivedReportEmail;
use App\Value\Reports\EnvelopeProcessingStatus;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class ReceivedReportEmailRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function existsForSourceAndMessageId(ReportSource $source, string $messageId): bool
    {
        return null !== $this->findForSourceAndMessageId($source, $messageId);
    }

    /**
     * The BYO poller needs the row itself, not just its existence: a message it
     * could not flag stays unseen and comes back on the next poll, and the
     * reports it carries must be linked to the envelope already on record
     * rather than to a second one.
     *
     * `$mailboxConnection` is not optional decoration — it is the tenant
     * boundary. `Message-ID` is chosen by whoever sent the mail, and
     * `ReportSource::ByoMailbox` is the same value for every customer, so
     * without the connection this lookup asks "has ANY tenant on the platform
     * seen this header?" and hands back a row belonging to a stranger. There is
     * no global Doctrine tenant filter to catch that (`config/packages/doctrine.php`
     * defines none, whatever CLAUDE.md says); the scoping has to be here.
     *
     * Passing null keeps the central-inbox behaviour exactly as it was: one
     * global mailbox, one namespace, matched only against rows that likewise
     * have no connection.
     */
    public function findForSourceAndMessageId(ReportSource $source, string $messageId, ?MailboxConnection $mailboxConnection = null): ?ReceivedReportEmail
    {
        return $this->entityManager->getRepository(ReceivedReportEmail::class)
            ->findOneBy([
                'source' => $source,
                'messageId' => $messageId,
                'mailboxConnection' => $mailboxConnection,
            ]);
    }

    public function get(UuidInterface $id): ReceivedReportEmail
    {
        $envelope = $this->entityManager->getRepository(ReceivedReportEmail::class)->find($id);

        if (null === $envelope) {
            throw new \RuntimeException(sprintf('Received report email %s not found.', $id->toString()));
        }

        return $envelope;
    }

    /** @return list<ReceivedReportEmail> */
    public function findOlderThan(\DateTimeImmutable $cutoff, EnvelopeProcessingStatus $status): array
    {
        /** @var list<ReceivedReportEmail> $result */
        $result = $this->entityManager->getRepository(ReceivedReportEmail::class)
            ->createQueryBuilder('e')
            ->where('e.processingStatus = :status')
            ->andWhere('e.processedAt < :cutoff')
            ->setParameter('status', $status)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
