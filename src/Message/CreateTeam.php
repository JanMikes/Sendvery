<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class CreateTeam
{
    public function __construct(
        public UuidInterface $teamId,
        public string $name,
        public UuidInterface $ownerUserId,
    ) {
    }
}
