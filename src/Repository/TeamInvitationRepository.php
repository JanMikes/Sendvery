<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeamInvitation;
use App\Exceptions\TeamInvitationNotFound;
use App\Value\TeamInvitationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class TeamInvitationRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * System-scoped lookup. Use ONLY from internal code paths. User-facing
     * controllers MUST go through {@see findForTeams()}.
     */
    public function get(UuidInterface $id): TeamInvitation
    {
        $invitation = $this->entityManager->find(TeamInvitation::class, $id);

        if (null === $invitation) {
            throw new TeamInvitationNotFound(sprintf('Team invitation %s not found.', $id->toString()));
        }

        return $invitation;
    }

    /**
     * @param list<UuidInterface> $teamIds
     */
    public function findForTeams(UuidInterface $id, array $teamIds): ?TeamInvitation
    {
        if ([] === $teamIds) {
            return null;
        }

        $invitation = $this->entityManager->find(TeamInvitation::class, $id);

        if (null === $invitation) {
            return null;
        }

        foreach ($teamIds as $teamId) {
            if ($invitation->team->id->equals($teamId)) {
                return $invitation;
            }
        }

        return null;
    }

    public function findByToken(string $token): ?TeamInvitation
    {
        return $this->entityManager->getRepository(TeamInvitation::class)
            ->findOneBy(['invitationToken' => $token]);
    }

    public function findActiveForTeamAndEmail(UuidInterface $teamId, string $email): ?TeamInvitation
    {
        return $this->entityManager->getRepository(TeamInvitation::class)
            ->findOneBy([
                'team' => $teamId->toString(),
                'invitedEmail' => strtolower(trim($email)),
                'status' => TeamInvitationStatus::Pending,
            ]);
    }

    /** @return list<TeamInvitation> */
    public function findPendingForEmail(string $email): array
    {
        /** @var list<TeamInvitation> $result */
        $result = $this->entityManager->getRepository(TeamInvitation::class)
            ->createQueryBuilder('i')
            ->where('i.invitedEmail = :email')
            ->andWhere('i.status = :pending')
            ->setParameter('email', strtolower(trim($email)))
            ->setParameter('pending', TeamInvitationStatus::Pending)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /** @return list<TeamInvitation> */
    public function findPendingForTeam(UuidInterface $teamId): array
    {
        /** @var list<TeamInvitation> $result */
        $result = $this->entityManager->getRepository(TeamInvitation::class)
            ->createQueryBuilder('i')
            ->where('i.team = :teamId')
            ->andWhere('i.status = :pending')
            ->setParameter('teamId', $teamId->toString())
            ->setParameter('pending', TeamInvitationStatus::Pending)
            ->orderBy('i.sentAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
