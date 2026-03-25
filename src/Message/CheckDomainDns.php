<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

final readonly class CheckDomainDns
{
    public function __construct(
        public UuidInterface $domainId,
    ) {
    }
}
