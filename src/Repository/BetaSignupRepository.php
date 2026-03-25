<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BetaSignup;
use Doctrine\ORM\EntityManagerInterface;

readonly final class BetaSignupRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByToken(string $token): ?BetaSignup
    {
        return $this->entityManager->getRepository(BetaSignup::class)->findOneBy([
            'confirmationToken' => $token,
        ]);
    }

    public function findByEmail(string $email): ?BetaSignup
    {
        return $this->entityManager->getRepository(BetaSignup::class)->findOneBy([
            'email' => $email,
        ]);
    }
}
