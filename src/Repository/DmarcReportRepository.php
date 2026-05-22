<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Exceptions\DmarcReportNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class DmarcReportRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * System-scoped lookup. Use ONLY from internal code paths where the
     * report id originates from trusted state. User-facing controllers MUST
     * go through {@see findForTeams()}.
     */
    public function get(UuidInterface $id): DmarcReport
    {
        $report = $this->entityManager->find(DmarcReport::class, $id);

        if (null === $report) {
            throw new DmarcReportNotFound(sprintf('DMARC report with ID "%s" not found.', $id->toString()));
        }

        return $report;
    }

    /**
     * Team-scoped lookup. Returns null when the report is missing or its
     * monitored domain belongs to a team the caller isn't a member of.
     *
     * @param list<UuidInterface> $teamIds
     */
    public function findForTeams(UuidInterface $id, array $teamIds): ?DmarcReport
    {
        if ([] === $teamIds) {
            return null;
        }

        $report = $this->entityManager->find(DmarcReport::class, $id);

        if (null === $report) {
            return null;
        }

        foreach ($teamIds as $teamId) {
            if ($report->monitoredDomain->team->id->equals($teamId)) {
                return $report;
            }
        }

        return null;
    }

    public function existsByExternalId(string $externalReportId, UuidInterface $domainId): bool
    {
        $count = $this->entityManager->getRepository(DmarcReport::class)->count([
            'externalReportId' => $externalReportId,
            'monitoredDomain' => $domainId->toString(),
        ]);

        return $count > 0;
    }

    /**
     * Hard-delete DMARC reports for one team whose `processedAt` is older
     * than the cutoff. Returns the row count removed. Used by the per-team
     * retention purge (`sendvery:dmarc:purge`) — retention is the one
     * sanctioned path where Sendvery deletes user data (DMARC reports are
     * the bulk of storage; envelopes are handled separately).
     */
    public function deleteOlderThanForTeam(UuidInterface $teamId, \DateTimeImmutable $cutoff): int
    {
        return (int) $this->entityManager
            ->createQuery(
                'DELETE FROM '.DmarcReport::class.' r
                 WHERE r.processedAt < :cutoff
                 AND r.monitoredDomain IN (
                    SELECT d.id FROM '.MonitoredDomain::class.' d WHERE d.team = :team
                 )'
            )
            ->setParameter('cutoff', $cutoff)
            ->setParameter('team', $teamId->toString())
            ->execute();
    }
}
