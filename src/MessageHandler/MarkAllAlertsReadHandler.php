<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\MarkAllAlertsRead;
use App\Repository\AlertRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MarkAllAlertsReadHandler
{
    public function __construct(
        private AlertRepository $alertRepository,
    ) {
    }

    public function __invoke(MarkAllAlertsRead $message): void
    {
        foreach ($this->alertRepository->findUnreadForTeam($message->teamId) as $alert) {
            $alert->markAsRead();
        }
        // Doctrine UoW flushes via the command bus' doctrine_transaction middleware.
    }
}
