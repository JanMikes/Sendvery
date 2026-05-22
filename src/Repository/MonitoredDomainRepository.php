<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredDomain;
use App\Exceptions\MonitoredDomainNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class MonitoredDomainRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(UuidInterface $id): MonitoredDomain
    {
        $domain = $this->entityManager->find(MonitoredDomain::class, $id);

        if (null === $domain) {
            throw new MonitoredDomainNotFound(sprintf('Monitored domain with ID "%s" not found.', $id->toString()));
        }

        return $domain;
    }

    public function findByDomain(string $domain, UuidInterface $teamId): ?MonitoredDomain
    {
        return $this->entityManager->getRepository(MonitoredDomain::class)->findOneBy([
            'domain' => $domain,
            'team' => $teamId->toString(),
        ]);
    }

    public function findLatestForTeam(UuidInterface $teamId): ?MonitoredDomain
    {
        return $this->entityManager->getRepository(MonitoredDomain::class)->findOneBy(
            ['team' => $teamId->toString()],
            ['createdAt' => 'DESC'],
        );
    }

    /**
     * Looks up the single monitored_domain row that has the given name AND a
     * non-null dmarc_verified_at. The partial unique index guarantees at most
     * one such row exists, so this is the canonical "who owns this domain?"
     * lookup for routing incoming DMARC reports.
     */
    public function findVerifiedByName(string $domainName): ?MonitoredDomain
    {
        $normalized = strtolower(trim($domainName));

        return $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(MonitoredDomain::class, 'd')
            ->where('LOWER(d.domain) = :name')
            ->andWhere('d.dmarcVerifiedAt IS NOT NULL')
            ->setParameter('name', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * True when some team has this domain verified. Used to surface the
     * "domain already monitored" hard-block on Add.
     */
    public function isVerifiedAnywhere(string $domainName): bool
    {
        return null !== $this->findVerifiedByName($domainName);
    }

    /** @return list<MonitoredDomain> */
    public function findAllUnverifiedWithName(string $domainName): array
    {
        $normalized = strtolower(trim($domainName));

        /** @var list<MonitoredDomain> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(MonitoredDomain::class, 'd')
            ->where('LOWER(d.domain) = :name')
            ->andWhere('d.dmarcVerifiedAt IS NULL')
            ->setParameter('name', $normalized)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
