<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class RemoveTeamMember
{
    public function __construct(
        public UuidInterface $membershipId,
    ) {
    }
}
