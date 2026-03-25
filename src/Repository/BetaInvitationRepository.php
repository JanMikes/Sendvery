<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BetaInvitation;
use App\Value\InvitationStatus;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BetaInvitationRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByToken(string $token): ?BetaInvitation
    {
        return $this->entityManager->getRepository(BetaInvitation::class)->findOneBy([
            'invitationToken' => $token,
        ]);
    }

    public function findPendingByEmail(string $email): ?BetaInvitation
    {
        return $this->entityManager->getRepository(BetaInvitation::class)->findOneBy([
            'email' => $email,
            'status' => InvitationStatus::Pending,
        ]);
    }
}
