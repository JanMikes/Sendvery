<?php

declare(strict_types=1);

namespace App\Services\Ai\Result;

final readonly class OnDemandExplanationResult
{
    public function __construct(
        public string $explanation,
    ) {
    }
}
