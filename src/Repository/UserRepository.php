<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Exceptions\UserNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

readonly final class UserRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(UuidInterface $id): User
    {
        $user = $this->entityManager->find(User::class, $id);

        if ($user === null) {
            throw new UserNotFound(sprintf('User with ID "%s" not found.', $id->toString()));
        }

        return $user;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
