<?php

declare(strict_types=1);

namespace App\Services\Ai\Result;

final readonly class KeyMetric
{
    public function __construct(
        public string $label,
        public string $value,
    ) {
    }
}
