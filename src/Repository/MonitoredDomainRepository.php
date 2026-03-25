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
}
