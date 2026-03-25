<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

final readonly class MailboxPollCompleted
{
    public function __construct(
        public UuidInterface $connectionId,
        public int $reportsFound,
        public int $errors,
    ) {
    }
}
