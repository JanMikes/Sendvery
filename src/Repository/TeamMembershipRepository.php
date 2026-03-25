<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeamMembership;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

readonly final class TeamMembershipRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<TeamMembership> */
    public function findForUser(UuidInterface $userId): array
    {
        return $this->entityManager->getRepository(TeamMembership::class)
            ->findBy(['user' => $userId->toString()]);
    }

    /** @return array<TeamMembership> */
    public function findForTeam(UuidInterface $teamId): array
    {
        return $this->entityManager->getRepository(TeamMembership::class)
            ->findBy(['team' => $teamId->toString()]);
    }

    public function findMembership(UuidInterface $userId, UuidInterface $teamId): ?TeamMembership
    {
        return $this->entityManager->getRepository(TeamMembership::class)
            ->findOneBy([
                'user' => $userId->toString(),
                'team' => $teamId->toString(),
            ]);
    }
}
