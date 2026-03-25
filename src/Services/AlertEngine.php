<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class AlertEngine
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createAlert(
        Team $team,
        ?MonitoredDomain $monitoredDomain,
        AlertType $type,
        AlertSeverity $severity,
        string $title,
        string $message,
        array $data = [],
    ): Alert {
        $alert = new Alert(
            id: $this->identityProvider->nextIdentity(),
            team: $team,
            monitoredDomain: $monitoredDomain,
            type: $type,
            severity: $severity,
            title: $title,
            message: $message,
            data: $data,
            createdAt: $this->clock->now(),
        );

        $this->entityManager->persist($alert);

        return $alert;
    }
}
