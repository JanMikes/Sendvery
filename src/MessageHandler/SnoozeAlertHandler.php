<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SnoozeAlert;
use App\Repository\AlertRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SnoozeAlertHandler
{
    public function __construct(
        private AlertRepository $alertRepository,
    ) {
    }

    public function __invoke(SnoozeAlert $message): void
    {
        $alert = $this->alertRepository->get($message->alertId);
        $alert->snoozeUntil($message->snoozedUntil);
        // Doctrine UoW flushes at request end via DomainEventsSubscriber.
    }
}
