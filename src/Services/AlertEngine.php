<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Repository\MutedAlertRepository;
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
        private MutedAlertRepository $mutedAlertRepository,
    ) {
    }

    /**
     * Single chokepoint for every RaiseAlert*-style handler. Returning null
     * here is the "silenced by user preference" signal — handlers ignore the
     * return value, so the mute check only needs to live in one place.
     *
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
    ): ?Alert {
        if (null !== $monitoredDomain && $this->mutedAlertRepository->isMuted(
            $team->id->toString(),
            $monitoredDomain->id->toString(),
            $type,
        )) {
            return null;
        }

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
