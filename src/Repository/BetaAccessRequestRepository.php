<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BetaAccessRequest;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BetaAccessRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<BetaAccessRequest> */
    public function findAllOrderedByRequestedAtDesc(): array
    {
        return $this->entityManager->getRepository(BetaAccessRequest::class)
            ->findBy([], ['requestedAt' => 'DESC']);
    }
}
