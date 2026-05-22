<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DomainOwnershipInquiry;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class DomainOwnershipInquiryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(UuidInterface $id): DomainOwnershipInquiry
    {
        $inquiry = $this->entityManager->find(DomainOwnershipInquiry::class, $id);
        if (null === $inquiry) {
            throw new \RuntimeException(sprintf('Domain ownership inquiry %s not found.', $id->toString()));
        }

        return $inquiry;
    }

    /**
     * True when this user has submitted an inquiry about this domain within
     * the given window — used to rate-limit button-spam (one per 24h).
     */
    public function hasRecentForUserAndDomain(UuidInterface $userId, string $domain, \DateTimeImmutable $since): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(DomainOwnershipInquiry::class, 'i')
            ->where('i.inquiringUser = :userId')
            ->andWhere('i.domain = :domain')
            ->andWhere('i.createdAt >= :since')
            ->setParameter('userId', $userId->toString())
            ->setParameter('domain', strtolower($domain))
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
