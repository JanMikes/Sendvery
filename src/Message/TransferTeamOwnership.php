<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class TransferTeamOwnership
{
    public function __construct(
        public UuidInterface $teamId,
        public UuidInterface $newOwnerUserId,
        public UuidInterface $currentOwnerUserId,
    ) {
    }
}
