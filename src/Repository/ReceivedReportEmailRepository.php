<?php

declare(strict_types=1);

namespace App\Repository;

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
        return null !== $this->entityManager->getRepository(ReceivedReportEmail::class)
            ->findOneBy(['source' => $source, 'messageId' => $messageId]);
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
