<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DnsCheckResult;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class DnsCheckResultRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findLatestForDomainAndType(UuidInterface $domainId, DnsCheckType $type): ?DnsCheckResult
    {
        return $this->entityManager->getRepository(DnsCheckResult::class)
            ->createQueryBuilder('d')
            ->where('d.monitoredDomain = :domainId')
            ->andWhere('d.type = :type')
            ->setParameter('domainId', $domainId->toString())
            ->setParameter('type', $type->value)
            ->orderBy('d.checkedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
