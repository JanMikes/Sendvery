<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\MarkAlertAsRead;
use App\Repository\AlertRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MarkAlertAsReadHandler
{
    public function __construct(
        private AlertRepository $alertRepository,
    ) {
    }

    public function __invoke(MarkAlertAsRead $message): void
    {
        $alert = $this->alertRepository->get($message->alertId);
        $alert->markAsRead();
    }
}
