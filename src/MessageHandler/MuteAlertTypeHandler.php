<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MutedAlert;
use App\Message\MuteAlertType;
use App\Repository\MonitoredDomainRepository;
use App\Repository\MutedAlertRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MuteAlertTypeHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TeamRepository $teamRepository,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private MutedAlertRepository $mutedAlertRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(MuteAlertType $message): void
    {
        // Idempotent — if the user double-clicks Mute, do nothing rather than
        // tripping the unique constraint.
        $existing = $this->mutedAlertRepository->findOneForTeamDomainType(
            $message->teamId->toString(),
            $message->domainId->toString(),
            $message->alertType,
        );

        if (null !== $existing) {
            return;
        }

        $team = $this->teamRepository->get($message->teamId);
        $domain = $this->monitoredDomainRepository->get($message->domainId);

        $muted = new MutedAlert(
            id: $message->mutedAlertId,
            team: $team,
            monitoredDomain: $domain,
            alertType: $message->alertType,
            mutedAt: $this->clock->now(),
        );

        $this->entityManager->persist($muted);

        // MutedAlert doesn't implement EntityWithEvents, so the
        // DomainEventsSubscriber::postFlush chain won't fire — we have to
        // flush explicitly here to actually write the row.
        $this->entityManager->flush();
    }
}
