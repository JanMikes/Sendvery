<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class PollMailbox
{
    public function __construct(
        public UuidInterface $connectionId,
    ) {
    }
}
