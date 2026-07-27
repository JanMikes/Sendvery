<?php

declare(strict_types=1);

namespace App\Services;

use App\Repository\IngestionSourceStatusRepository;
use App\Value\IngestionSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stamps "this pipeline worked" for a shared ingestion source.
 *
 * Kept as its own service rather than folded into the ingestor so the write is
 * a single, greppable statement of intent — the ingestor is already the most
 * heavily-conditioned code path in the app, and burying the one fact the
 * alerting layer depends on inside it invites the fact being lost in a refactor.
 */
final readonly class IngestionHealthRecorder
{
    public function __construct(
        private IngestionSourceStatusRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Flushes immediately rather than leaving the write to the caller's unit of
     * work. The ingestor already flushes per envelope for the same reason
     * (dedupe depends on the unique constraint being hit), and a poll that
     * succeeded should have that recorded even if a later, unrelated step in
     * the same request throws.
     */
    public function recordSuccess(IngestionSource $source, \DateTimeImmutable $at): void
    {
        $this->repository->getOrCreate($source)->recordSuccess($at);

        $this->entityManager->flush();
    }
}
