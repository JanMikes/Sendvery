<?php

declare(strict_types=1);

namespace App\Services\Ai\Result;

final readonly class SuggestedDnsRecord
{
    public function __construct(
        public string $type,
        public string $host,
        public string $value,
    ) {
    }
}
