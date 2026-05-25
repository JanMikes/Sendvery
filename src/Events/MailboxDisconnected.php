<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * Emitted when a user soft-deletes a {@see \App\Entity\MailboxConnection} via
 * the TASK-133 disconnect flow. Carries the connection + team IDs so future
 * handlers (audit trail, billing recalcs, downstream notifications) can react
 * without re-loading the entity.
 */
final readonly class MailboxDisconnected
{
    public function __construct(
        public UuidInterface $connectionId,
        public UuidInterface $teamId,
    ) {
    }
}
