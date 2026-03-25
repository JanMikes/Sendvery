<?php

declare(strict_types=1);

namespace App\Value\Dns;

final readonly class HealthCategory
{
    public function __construct(
        public string $name,
        public int $score,
        public string $status,
    ) {
    }
}
