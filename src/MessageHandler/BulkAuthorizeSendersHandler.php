<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\BulkAuthorizeSenders;
use App\Repository\KnownSenderRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class BulkAuthorizeSendersHandler
{
    public function __construct(
        private KnownSenderRepository $knownSenderRepository,
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(BulkAuthorizeSenders $message): void
    {
        $actor = $this->userRepository->get($message->actorUserId);
        $now = $this->clock->now();

        foreach ($message->senderIds as $senderId) {
            // Defense-in-depth: even though the controller has already CSRF-
            // validated the request, a forged id in senderIds[] could belong
            // to another tenant. findForTeam silently returns null for those,
            // so we skip rather than partially apply.
            $sender = $this->knownSenderRepository->findForTeam($senderId, $message->teamId);

            if (null === $sender) {
                continue;
            }

            $sender->authorize($actor, $now);
        }
        // Doctrine UoW flushes at request end via doctrine_transaction middleware.
    }
}
