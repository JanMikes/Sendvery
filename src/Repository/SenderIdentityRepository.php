<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SenderIdentity;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Access to the global sender-identity cache (DEC-059 §3.1).
 *
 * There is no team scoping here on purpose: the table holds objective network
 * facts (PTR hostname, registrable domain, organisation) that are identical for
 * every tenant. Nothing tenant-specific is stored, so nothing tenant-specific
 * can leak.
 */
final readonly class SenderIdentityRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByIp(string $sourceIp): ?SenderIdentity
    {
        return $this->entityManager->getRepository(SenderIdentity::class)
            ->findOneBy(['sourceIp' => $sourceIp]);
    }

    /**
     * Batch lookup — the primary entry point, because report ingest resolves
     * every source IP in a report at once and must not issue one query per IP.
     *
     * @param list<string> $sourceIps
     *
     * @return array<string, SenderIdentity> keyed by source IP; missing IPs are absent
     */
    public function findByIps(array $sourceIps): array
    {
        if ([] === $sourceIps) {
            return [];
        }

        $identities = $this->entityManager->getRepository(SenderIdentity::class)
            ->createQueryBuilder('si')
            ->where('si.sourceIp IN (:sourceIps)')
            ->setParameter('sourceIps', array_values(array_unique($sourceIps)))
            ->getQuery()
            ->getResult();

        $byIp = [];

        foreach ($identities as $identity) {
            $byIp[$identity->sourceIp] = $identity;
        }

        return $byIp;
    }

    /**
     * Schedules a newly discovered identity for insert. Never flushes — the
     * command/event bus `doctrine_transaction` middleware owns that.
     */
    public function add(SenderIdentity $identity): void
    {
        $this->entityManager->persist($identity);
    }
}
