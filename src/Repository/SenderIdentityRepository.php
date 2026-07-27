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
        return $this->findByIps([$sourceIp])[$sourceIp] ?? null;
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
        $wanted = array_values(array_unique($sourceIps));

        if ([] === $wanted) {
            return [];
        }

        $identities = $this->entityManager->getRepository(SenderIdentity::class)
            ->createQueryBuilder('si')
            ->where('si.sourceIp IN (:sourceIps)')
            ->setParameter('sourceIps', $wanted)
            ->getQuery()
            ->getResult();

        $byIp = [];

        foreach ($identities as $identity) {
            $byIp[$identity->sourceIp] = $identity;
        }

        foreach ($this->pendingInserts($wanted) as $sourceIp => $identity) {
            $byIp[$sourceIp] = $identity;
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

    /**
     * Identities already discovered in *this* transaction but not yet written.
     *
     * A DQL query only sees flushed rows, and no handler is allowed to flush —
     * the bus middleware does that once, at the end. So two handlers of the same
     * `DmarcReportProcessed` event (sender inventory and the new-sender alert)
     * would each miss a brand-new address, each create a row for it, and the
     * closing flush would die on `uniq_sender_identity_source_ip`, rolling back
     * the entire report ingest. Reading pending inserts back makes the cache
     * authoritative within a transaction as well as across them — and, as a
     * bonus, stops the same address being reverse-looked-up twice per report.
     *
     * @param list<string> $sourceIps
     *
     * @return array<string, SenderIdentity>
     */
    private function pendingInserts(array $sourceIps): array
    {
        $pending = [];

        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $scheduled) {
            if ($scheduled instanceof SenderIdentity && in_array($scheduled->sourceIp, $sourceIps, true)) {
                $pending[$scheduled->sourceIp] = $scheduled;
            }
        }

        return $pending;
    }
}
