<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\PassRateRegressionSeverity;

/**
 * Verdict from {@see \App\Services\PassRateRegressionAdvisor} for the
 * pass-rate regression banner on `/app/reports` (TASK-093). Pure render-time
 * DTO consumed by {@see \App\Twig\Components\PassRateRegressionBanner} — the
 * template branches on `severity` and never re-derives any rule from the
 * underlying 7d/30d numbers.
 *
 * `topFailingSender` is nullable because the banner can render in the
 * Improvement state (and even Regression, in pathological setups where every
 * failing IP is below the cardinality threshold) without naming a single
 * culprit; the banner just hides the "investigate this sender" link in that
 * case.
 */
final readonly class PassRateRegressionResult
{
    public function __construct(
        public PassRateRegressionSeverity $severity,
        public float $currentRate7d,
        public float $baselineRate30d,
        public float $delta,
        public ?TopFailingSenderResult $topFailingSender,
        public int $totalFailingMessages7d,
    ) {
    }

    public static function stable(float $currentRate7d, float $baselineRate30d): self
    {
        return new self(
            severity: PassRateRegressionSeverity::Stable,
            currentRate7d: $currentRate7d,
            baselineRate30d: $baselineRate30d,
            delta: $currentRate7d - $baselineRate30d,
            topFailingSender: null,
            totalFailingMessages7d: 0,
        );
    }

    public function percentFromTopSender(): ?float
    {
        if (null === $this->topFailingSender) {
            return null;
        }

        if (0 === $this->totalFailingMessages7d) {
            return null;
        }

        return round(
            $this->topFailingSender->failingMessageCount / $this->totalFailingMessages7d * 100,
            0,
        );
    }
}
