<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DisconnectMailbox;
use App\Repository\MailboxConnectionRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * TASK-133: soft-deletes a mailbox by stamping `disconnectedAt`. The repository
 * `get()` call (used here, NOT `findActiveConnections`/`findByTeam`) bypasses
 * the disconnected-filter so a second disconnect of an already-disconnected
 * mailbox still resolves the entity and refreshes the timestamp idempotently.
 * The Doctrine entity manager's `postFlush` listener picks the
 * {@see \App\Events\MailboxDisconnected} event off the entity and dispatches it.
 */
#[AsMessageHandler]
final readonly class DisconnectMailboxHandler
{
    public function __construct(
        private MailboxConnectionRepository $mailboxConnectionRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DisconnectMailbox $message): void
    {
        $connection = $this->mailboxConnectionRepository->get($message->mailboxId);

        $connection->disconnect($this->clock->now());
    }
}
