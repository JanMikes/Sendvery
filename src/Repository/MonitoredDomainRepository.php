<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoredDomain;
use App\Exceptions\MonitoredDomainNotFound;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class MonitoredDomainRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * System-scoped lookup. Use ONLY from internal code paths (event handlers,
     * ingestion workers, cron commands) where the domain id originates from
     * trusted state. User-facing controllers MUST go through {@see findForTeams()}.
     */
    public function get(UuidInterface $id): MonitoredDomain
    {
        $domain = $this->entityManager->find(MonitoredDomain::class, $id);

        if (null === $domain) {
            throw new MonitoredDomainNotFound(sprintf('Monitored domain with ID "%s" not found.', $id->toString()));
        }

        return $domain;
    }

    /**
     * Team-scoped lookup for user-facing controllers. Returns null if the
     * domain is missing OR belongs to a team the caller isn't a member of —
     * callers translate that into a 404 instead of leaking the existence of
     * other tenants' domains.
     *
     * @param list<UuidInterface> $teamIds team UUIDs the caller belongs to
     */
    public function findForTeams(UuidInterface $id, array $teamIds): ?MonitoredDomain
    {
        if ([] === $teamIds) {
            return null;
        }

        $domain = $this->entityManager->find(MonitoredDomain::class, $id);

        if (null === $domain) {
            return null;
        }

        foreach ($teamIds as $teamId) {
            if ($domain->team->id->equals($teamId)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * All managed-CNAME domains owned by a team. Used by the downgrade-freeze
     * handler to pause auto-ramp on every managed domain (never loosening).
     *
     * @return list<MonitoredDomain>
     */
    public function findManagedDomainsForTeam(UuidInterface $teamId): array
    {
        /** @var list<MonitoredDomain> $domains */
        $domains = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(MonitoredDomain::class, 'd')
            ->where('d.team = :teamId')
            ->andWhere('d.dmarcSetupMode = :mode')
            ->setParameter('teamId', $teamId->toString())
            ->setParameter('mode', DmarcSetupMode::ManagedCname->value)
            ->getQuery()
            ->getResult();

        return $domains;
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
     * Looks up the single monitored_domain row that has the given name
     * (case-insensitive). The system-wide unique index guarantees at most one
     * row per domain, so this is the canonical "who owns this domain?"
     * lookup for routing incoming DMARC reports and for the Add-time
     * "domain taken" check.
     */
    public function findAnyByName(string $domainName): ?MonitoredDomain
    {
        $normalized = strtolower(trim($domainName));

        return $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(MonitoredDomain::class, 'd')
            ->where('LOWER(d.domain) = :name')
            ->setParameter('name', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
