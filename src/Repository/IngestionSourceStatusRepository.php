<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IngestionSourceStatus;
use App\Services\IdentityProvider;
use App\Value\IngestionSource;
use Doctrine\ORM\EntityManagerInterface;

final readonly class IngestionSourceStatusRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IdentityProvider $identityProvider,
    ) {
    }

    /**
     * Reads the row without creating one. Returns null when this source has
     * never been polled — a state callers must distinguish from "unhealthy",
     * because it is also the state of every fresh deployment.
     */
    public function find(IngestionSource $source): ?IngestionSourceStatus
    {
        return $this->entityManager
            ->getRepository(IngestionSourceStatus::class)
            ->findOneBy(['source' => $source]);
    }

    /**
     * Row for this source, created on first use. Only the pollers call this —
     * read paths use {@see find()} so that merely rendering a status page never
     * fabricates evidence that a pipeline exists.
     */
    public function getOrCreate(IngestionSource $source): IngestionSourceStatus
    {
        $status = $this->find($source);

        if (null !== $status) {
            return $status;
        }

        $status = new IngestionSourceStatus(
            id: $this->identityProvider->nextIdentity(),
            source: $source,
        );

        $this->entityManager->persist($status);

        return $status;
    }
}
