<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * TASK-133: soft-delete a {@see \App\Entity\MailboxConnection} on behalf of the
 * user. Dispatched by {@see \App\Controller\Dashboard\DisconnectMailboxController}
 * after CSRF + team-ownership checks. The handler is idempotent — re-dispatching
 * for an already-disconnected mailbox just refreshes the timestamp.
 */
final readonly class DisconnectMailbox
{
    public function __construct(
        public UuidInterface $mailboxId,
    ) {
    }
}
