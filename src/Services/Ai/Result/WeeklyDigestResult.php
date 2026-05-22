<?php

declare(strict_types=1);

namespace App\Services\Ai\Result;

final readonly class WeeklyDigestResult
{
    /**
     * @param list<KeyMetric> $keyMetrics
     * @param list<string>    $recommendations
     */
    public function __construct(
        public string $summaryMarkdown,
        public array $keyMetrics,
        public array $recommendations,
    ) {
    }
}
