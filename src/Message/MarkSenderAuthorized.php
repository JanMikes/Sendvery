<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class MarkSenderAuthorized
{
    public function __construct(
        public UuidInterface $senderId,
        public bool $isAuthorized,
    ) {
    }
}
