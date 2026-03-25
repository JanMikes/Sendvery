<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class MailboxConnectionCreated
{
    public function __construct(
        public UuidInterface $connectionId,
        public UuidInterface $teamId,
    ) {
    }
}
