<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuarantinedDmarcReport;
use App\Value\Reports\QuarantineReason;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class QuarantinedDmarcReportRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(UuidInterface $id): ?QuarantinedDmarcReport
    {
        return $this->entityManager->find(QuarantinedDmarcReport::class, $id);
    }

    /** @return list<QuarantinedDmarcReport> */
    public function findForDomain(string $domainName): array
    {
        /** @var list<QuarantinedDmarcReport> $result */
        $result = $this->entityManager->getRepository(QuarantinedDmarcReport::class)
            ->createQueryBuilder('q')
            ->where('q.domainName = :domain')
            ->setParameter('domain', strtolower($domainName))
            ->orderBy('q.quarantinedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Rows the TTL purge may delete: past `expires_at` AND holding a reason
     * whose lifecycle is a TTL at all. Plan-overage rows are excluded in SQL
     * rather than skipped in PHP because they are never deleted — they would
     * otherwise be re-hydrated (blob and all) on every nightly run, forever.
     *
     * @return list<QuarantinedDmarcReport>
     */
    public function findExpired(\DateTimeImmutable $now): array
    {
        /** @var list<QuarantinedDmarcReport> $result */
        $result = $this->entityManager->getRepository(QuarantinedDmarcReport::class)
            ->createQueryBuilder('q')
            ->where('q.expiresAt < :now')
            ->andWhere('q.reason IN (:purgeableReasons)')
            ->setParameter('now', $now)
            ->setParameter('purgeableReasons', QuarantineReason::ttlPurgeable())
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countForDomain(string $domainName): int
    {
        $count = $this->entityManager->getRepository(QuarantinedDmarcReport::class)
            ->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.domainName = :domain')
            ->setParameter('domain', strtolower($domainName))
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
