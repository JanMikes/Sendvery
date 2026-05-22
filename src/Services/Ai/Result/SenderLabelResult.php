<?php

declare(strict_types=1);

namespace App\Services\Ai\Result;

final readonly class SenderLabelResult
{
    public function __construct(
        public string $label,
        public float $confidence,
    ) {
    }
}
