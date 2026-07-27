<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Clears the whole unread backlog of a team, not just the page the user is
 * looking at — the alerts list is capped at 50 rows, so a per-row bulk action
 * can never reach the tail of a busy account.
 */
final readonly class MarkAllAlertsRead
{
    public function __construct(
        public UuidInterface $teamId,
    ) {
    }
}
