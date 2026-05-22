<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Alert;
use App\Exceptions\AlertNotFound;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;

final readonly class AlertRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * System-scoped lookup. Use ONLY from internal code paths where the alert
     * id originates from trusted state. User-facing controllers MUST go
     * through {@see findForTeams()}.
     */
    public function get(UuidInterface $id): Alert
    {
        $alert = $this->entityManager->find(Alert::class, $id);

        if (null === $alert) {
            throw new AlertNotFound(sprintf('Alert with ID "%s" not found.', $id->toString()));
        }

        return $alert;
    }

    /**
     * Team-scoped lookup. Returns null when the alert is missing or owned by
     * a team the caller isn't a member of, so controllers translate to a 404
     * without leaking the existence of other tenants' alerts.
     *
     * @param list<UuidInterface> $teamIds
     */
    public function findForTeams(UuidInterface $id, array $teamIds): ?Alert
    {
        if ([] === $teamIds) {
            return null;
        }

        $alert = $this->entityManager->find(Alert::class, $id);

        if (null === $alert) {
            return null;
        }

        foreach ($teamIds as $teamId) {
            if ($alert->team->id->equals($teamId)) {
                return $alert;
            }
        }

        return null;
    }
}
