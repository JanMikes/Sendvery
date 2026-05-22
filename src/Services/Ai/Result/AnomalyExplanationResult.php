<?php

declare(strict_types=1);

namespace App\Services\Ai\Result;

final readonly class AnomalyExplanationResult
{
    public function __construct(
        public string $explanation,
        public string $severity,
        public string $recommendedAction,
    ) {
    }
}
