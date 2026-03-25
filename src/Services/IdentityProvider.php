<?php

declare(strict_types=1);

namespace App\Services;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final readonly class IdentityProvider
{
    public function nextIdentity(): UuidInterface
    {
        return Uuid::uuid7();
    }
}
