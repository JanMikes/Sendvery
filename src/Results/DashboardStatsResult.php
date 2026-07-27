<?php

declare(strict_types=1);

namespace App\Results;

/**
 * NO-DATA CONTRACT — `$overallPassRate` is `null`, never `0.0`, when the team
 * has no DMARC records in the 30-day window. `0.0` means "we counted messages
 * and every one of them failed authentication"; `null` means "we have nothing
 * to report on yet".
 *
 * Same contract as {@see DomainOverviewResult::$passRate}, for the same reason:
 * report rows only exist once `sendvery:reports:poll-inbox` has ingested
 * something, so before the first poll a correctly-configured team was shown a
 * red `0.0%` "DMARC Pass Rate" card on `/app` — one card away from the domain
 * cards that correctly said "Waiting for first report".
 *
 * Templates must branch on {@see hasPassRateData()} and render the null state
 * through the `pass_rate_*` macros in `components/_severity_glyph.html.twig`.
 */
final readonly class DashboardStatsResult
{
    public function __construct(
        public int $totalDomains,
        public int $totalReportsLast30Days,
        /** 30-day DMARC pass rate as a percentage, or null when no DMARC records exist for the team in the window. Never conflate with 0.0. */
        public ?float $overallPassRate,
        public int $totalMessages,
    ) {
    }

    public function hasPassRateData(): bool
    {
        return null !== $this->overallPassRate;
    }
}
