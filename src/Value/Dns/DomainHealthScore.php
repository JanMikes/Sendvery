<?php

declare(strict_types=1);

namespace App\Value\Dns;

readonly final class DomainHealthScore
{
    /**
     * @param array<HealthCategory> $categories
     */
    public function __construct(
        public string $grade,
        public int $score,
        public array $categories,
    ) {
    }

    public function gradeColor(): string
    {
        return match ($this->grade) {
            'A' => 'text-success',
            'B' => 'text-info',
            'C' => 'text-warning',
            'D' => 'text-error',
            default => 'text-error',
        };
    }
}
