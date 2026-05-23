<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\UnsnoozeAlert;
use App\Repository\AlertRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UnsnoozeAlertHandler
{
    public function __construct(
        private AlertRepository $alertRepository,
    ) {
    }

    public function __invoke(UnsnoozeAlert $message): void
    {
        $alert = $this->alertRepository->get($message->alertId);
        $alert->unsnooze();
        // Doctrine UoW flushes at request end via DomainEventsSubscriber.
    }
}
