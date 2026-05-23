<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\BulkMarkAlertsRead;
use App\Repository\AlertRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class BulkMarkAlertsReadHandler
{
    public function __construct(
        private AlertRepository $alertRepository,
    ) {
    }

    public function __invoke(BulkMarkAlertsRead $message): void
    {
        foreach ($message->alertIds as $alertId) {
            // Defense-in-depth: even though the controller has already CSRF-
            // validated the request, a forged id in alertIds[] could belong
            // to another tenant. findForTeams silently returns null for
            // those, so we skip rather than partially apply.
            $alert = $this->alertRepository->findForTeams($alertId, [$message->teamId]);

            if (null === $alert) {
                continue;
            }

            $alert->markAsRead();
        }
        // Doctrine UoW flushes at request end via DomainEventsSubscriber.
    }
}
