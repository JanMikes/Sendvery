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

    /**
     * System-scoped lookup. Use ONLY from internal code paths. User-facing
     * controllers MUST go through {@see findForTeams()}.
     */
    public function get(UuidInterface $id): TeamMembership
    {
        $membership = $this->entityManager->find(TeamMembership::class, $id);
        if (null === $membership) {
            throw new TeamMembershipNotFound(sprintf('Team membership %s not found.', $id->toString()));
        }

        return $membership;
    }

    /**
     * @param list<UuidInterface> $teamIds
     */
    public function findForTeams(UuidInterface $id, array $teamIds): ?TeamMembership
    {
        if ([] === $teamIds) {
            return null;
        }

        $membership = $this->entityManager->find(TeamMembership::class, $id);

        if (null === $membership) {
            return null;
        }

        foreach ($teamIds as $teamId) {
            if ($membership->team->id->equals($teamId)) {
                return $membership;
            }
        }

        return null;
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
