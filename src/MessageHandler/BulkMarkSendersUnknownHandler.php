<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\BulkMarkSendersUnknown;
use App\Repository\KnownSenderRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class BulkMarkSendersUnknownHandler
{
    public function __construct(
        private KnownSenderRepository $knownSenderRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(BulkMarkSendersUnknown $message): void
    {
        $actor = $this->userRepository->get($message->actorUserId);
        $now = $this->clock->now();

        foreach ($message->senderIds as $senderId) {
            $sender = $this->knownSenderRepository->findForTeam($senderId, $message->teamId);

            if (null === $sender) {
                continue;
            }

            $sender->markUnknown($actor, $now);
        }
        // Doctrine UoW flushes at request end via doctrine_transaction middleware.
    }
}
