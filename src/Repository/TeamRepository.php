<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Team;
use App\Exceptions\TeamNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class TeamRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(UuidInterface $id): Team
    {
        $team = $this->entityManager->find(Team::class, $id);

        if (null === $team) {
            throw new TeamNotFound(sprintf('Team with ID "%s" not found.', $id->toString()));
        }

        return $team;
    }

    public function findBySlug(string $slug): ?Team
    {
        return $this->entityManager->getRepository(Team::class)->findOneBy(['slug' => $slug]);
    }
}
