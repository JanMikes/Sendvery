<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Sends a courtesy email to both sides of an ownership transfer right after
 * TransferTeamOwnershipHandler swaps the roles. Dispatched from inside the
 * handler so a failed send retries via the worker without rolling back the
 * actual role swap.
 */
final readonly class SendOwnershipTransferNotifications
{
    public function __construct(
        public UuidInterface $teamId,
        public UuidInterface $newOwnerUserId,
        public UuidInterface $previousOwnerUserId,
    ) {
    }
}
