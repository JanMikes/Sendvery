<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class RequestMagicLink
{
    public function __construct(
        public UuidInterface $tokenId,
        public string $email,
    ) {
    }
}
