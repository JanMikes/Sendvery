<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MagicLinkToken;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MagicLinkTokenRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByToken(string $token): ?MagicLinkToken
    {
        return $this->entityManager->getRepository(MagicLinkToken::class)
            ->findOneBy(['token' => $token]);
    }

    public function countRecentByEmail(string $email, \DateTimeImmutable $since): int
    {
        return (int) $this->entityManager->getRepository(MagicLinkToken::class)
            ->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.email = :email')
            ->andWhere('t.createdAt >= :since')
            ->setParameter('email', $email)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
