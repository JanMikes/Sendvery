<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiInsight;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AiInsightRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByCacheKey(string $cacheKey): ?AiInsight
    {
        return $this->entityManager->getRepository(AiInsight::class)->findOneBy(['cacheKey' => $cacheKey]);
    }
}
