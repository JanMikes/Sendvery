<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\BulkSnoozeAlerts;
use App\Repository\AlertRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class BulkSnoozeAlertsHandler
{
    public function __construct(
        private AlertRepository $alertRepository,
    ) {
    }

    public function __invoke(BulkSnoozeAlerts $message): void
    {
        foreach ($message->alertIds as $alertId) {
            // Defense-in-depth team scope check — see BulkMarkAlertsReadHandler.
            $alert = $this->alertRepository->findForTeams($alertId, [$message->teamId]);

            if (null === $alert) {
                continue;
            }

            $alert->snoozeUntil($message->snoozedUntil);
        }
        // Doctrine UoW flushes at request end via DomainEventsSubscriber.
    }
}
