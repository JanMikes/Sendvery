<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\MarkSenderAuthorized;
use App\Repository\KnownSenderRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MarkSenderAuthorizedHandler
{
    public function __construct(
        private KnownSenderRepository $knownSenderRepository,
    ) {
    }

    public function __invoke(MarkSenderAuthorized $message): void
    {
        $sender = $this->knownSenderRepository->get($message->senderId);
        $sender->isAuthorized = $message->isAuthorized;
    }
}
