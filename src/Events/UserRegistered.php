<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class UserRegistered
{
    public function __construct(
        public UuidInterface $userId,
        public string $email,
    ) {
    }
}
