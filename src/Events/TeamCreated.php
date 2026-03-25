<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class TeamCreated
{
    public function __construct(
        public UuidInterface $teamId,
    ) {
    }
}
