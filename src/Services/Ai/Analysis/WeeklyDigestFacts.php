<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * Pre-computed, sanitized team-week summary for the weekly-digest prompt —
 * projected from {@see \App\Value\WeeklyDigestData} by the orchestrator. Counts
 * (not raw sender names) are surfaced so untrusted, attacker-influenceable
 * strings don't enter the prompt; domain names and broken-DNS labels are
 * sanitized.
 */
final readonly class WeeklyDigestFacts
{
    /**
     * @param list<WeeklyDigestDomainFact> $domains
     * @param list<string>                 $brokenDns sanitized "domain (TYPE)" labels
     */
    public function __construct(
        public string $teamName,
        public string $periodLabel,
        public int $totalDomains,
        public int $totalMessages,
        public float $averagePassRate,
        public int $alertsCount,
        public int $dnsChangesCount,
        public array $domains,
        public array $brokenDns,
    ) {
    }
}
