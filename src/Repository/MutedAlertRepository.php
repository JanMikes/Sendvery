<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MutedAlert;
use App\Value\AlertType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class MutedAlertRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $database,
    ) {
    }

    /**
     * Hot path called by {@see \App\Services\AlertEngine::createAlert}.
     * Uses raw DBAL + the (team_id, monitored_domain_id, alert_type)
     * unique index so the lookup is a single B-tree probe even at
     * tens-of-thousands of rows.
     */
    public function isMuted(string $teamId, string $domainId, AlertType $type): bool
    {
        $row = $this->database->executeQuery(
            'SELECT 1 FROM muted_alert
             WHERE team_id = :teamId
             AND monitored_domain_id = :domainId
             AND alert_type = :alertType
             LIMIT 1',
            [
                'teamId' => $teamId,
                'domainId' => $domainId,
                'alertType' => $type->value,
            ],
        )->fetchOne();

        return false !== $row;
    }

    /**
     * @return list<MutedAlert>
     */
    public function findForTeam(string $teamId): array
    {
        return array_values($this->entityManager->getRepository(MutedAlert::class)->findBy(
            ['team' => $teamId],
            ['mutedAt' => 'DESC'],
        ));
    }

    public function findOneForTeamDomainType(string $teamId, string $domainId, AlertType $type): ?MutedAlert
    {
        return $this->entityManager->getRepository(MutedAlert::class)->findOneBy([
            'team' => $teamId,
            'monitoredDomain' => $domainId,
            'alertType' => $type,
        ]);
    }

    public function get(UuidInterface $id): MutedAlert
    {
        $muted = $this->entityManager->find(MutedAlert::class, $id);

        if (null === $muted) {
            throw new \RuntimeException(sprintf('Muted alert with ID "%s" not found.', $id->toString()));
        }

        return $muted;
    }

    /**
     * Team-scoped lookup mirroring {@see AlertRepository::findForTeams}. Used
     * by user-facing controllers to translate cross-tenant or missing IDs to
     * a 404 without leaking the existence of other tenants' rows.
     *
     * @param list<UuidInterface> $teamIds
     */
    public function findForTeams(UuidInterface $id, array $teamIds): ?MutedAlert
    {
        if ([] === $teamIds) {
            return null;
        }

        $muted = $this->entityManager->find(MutedAlert::class, $id);

        if (null === $muted) {
            return null;
        }

        foreach ($teamIds as $teamId) {
            if ($muted->team->id->equals($teamId)) {
                return $muted;
            }
        }

        return null;
    }
}
