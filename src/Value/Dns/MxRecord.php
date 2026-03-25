<?php

declare(strict_types=1);

namespace App\Value\Dns;

readonly final class MxRecord
{
    public function __construct(
        public string $host,
        public int $priority,
        public ?string $ip,
        public bool $reachable,
        public ?bool $tlsSupported,
    ) {
    }
}
