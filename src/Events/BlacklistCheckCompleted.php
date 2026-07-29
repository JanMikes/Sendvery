<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

final readonly class BlacklistCheckCompleted
{
    /**
     * @param list<string> $listedOn DNSBL hostnames that returned a genuine
     *                               listing code — never one that refused the query
     */
    public function __construct(
        public UuidInterface $domainId,
        public string $ipAddress,
        public bool $isListed,
        public array $listedOn,
    ) {
    }
}
