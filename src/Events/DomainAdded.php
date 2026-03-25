<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class DomainAdded
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
    ) {
    }
}
