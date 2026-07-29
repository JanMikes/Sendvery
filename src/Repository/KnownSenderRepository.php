<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\KnownSender;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class KnownSenderRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(UuidInterface $id): KnownSender
    {
        $sender = $this->entityManager->find(KnownSender::class, $id);

        if (null === $sender) {
            throw new \RuntimeException(sprintf('Known sender with ID "%s" not found.', $id->toString()));
        }

        return $sender;
    }

    /**
     * Looks up a sender that belongs to a domain owned by the given team.
     * Used by every write controller + the bulk handlers to enforce
     * tenant scoping at the persistence boundary — returns null for
     * unknown IDs, IDs from another tenant, or forged IDs.
     */
    public function findForTeam(UuidInterface $id, UuidInterface $teamId): ?KnownSender
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('ks')
            ->from(KnownSender::class, 'ks')
            ->join('ks.monitoredDomain', 'md')
            ->where('ks.id = :id')
            ->andWhere('md.team = :teamId')
            ->setParameter('id', $id->toString())
            ->setParameter('teamId', $teamId->toString());

        $result = $qb->getQuery()->getOneOrNullResult();

        return $result instanceof KnownSender ? $result : null;
    }

    /**
     * The sender record behind a sending IP, so an alert about that IP can say
     * what it actually is.
     *
     * A blacklist alert naming a bare address makes the reader guess whether it
     * is their own mail server or their ESP's shared relay — which is the whole
     * difference between "go and delist it" and "you cannot delist this and
     * probably should not try".
     */
    public function findByDomainAndIp(UuidInterface $domainId, string $sourceIp): ?KnownSender
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('ks')
            ->from(KnownSender::class, 'ks')
            ->where('ks.monitoredDomain = :domainId')
            ->andWhere('ks.sourceIp = :sourceIp')
            ->setParameter('domainId', $domainId->toString())
            ->setParameter('sourceIp', $sourceIp)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof KnownSender ? $result : null;
    }
}
