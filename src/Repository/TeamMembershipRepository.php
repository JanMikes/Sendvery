<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeamMembership;
use App\Exceptions\TeamMembershipNotFound;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class TeamMembershipRepository
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

    public function get(UuidInterface $id): TeamMembership
    {
        $membership = $this->entityManager->find(TeamMembership::class, $id);
        if (null === $membership) {
            throw new TeamMembershipNotFound(sprintf('Team membership %s not found.', $id->toString()));
        }

        return $membership;
    }

    public function findOwnerForTeam(UuidInterface $teamId): ?TeamMembership
    {
        return $this->entityManager->getRepository(TeamMembership::class)
            ->findOneBy([
                'team' => $teamId->toString(),
                'role' => TeamRole::Owner,
            ]);
    }
}
