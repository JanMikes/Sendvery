<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

final readonly class BlacklistCheckCompleted
{
    /**
     * @param array<string> $listedOn
     */
    public function __construct(
        public UuidInterface $domainId,
        public string $ipAddress,
        public bool $isListed,
        public array $listedOn,
    ) {
    }
}
