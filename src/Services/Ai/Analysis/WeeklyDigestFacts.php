<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * Pre-computed, sanitized team-week summary for the weekly-digest prompt —
 * projected from {@see \App\Value\WeeklyDigestData} by the orchestrator. Counts
 * (not raw sender names) are surfaced so untrusted, attacker-influenceable
 * strings don't enter the prompt; domain names and broken-DNS labels are
 * sanitized.
 *
 * {@see $newSenderRoles} keeps that guarantee: a role is one of five fixed enum
 * cases and its count is an integer, so telling the model *what kind* of senders
 * appeared adds no free text a sending host could author.
 */
final readonly class WeeklyDigestFacts
{
    /**
     * @param list<SenderRoleCount>        $newSenderRoles what kind of senders were newly discovered team-wide
     * @param list<WeeklyDigestDomainFact> $domains
     * @param list<string>                 $brokenDns      sanitized "domain (TYPE)" labels
     */
    public function __construct(
        public string $teamName,
        public string $periodLabel,
        public int $totalDomains,
        public int $totalMessages,
        /**
         * Message-weighted across every domain — `totalPassed / totalMessages`,
         * not the mean of the per-domain percentages. Null when the team
         * received no messages at all, which is not the same as 0%.
         */
        public ?float $overallPassRate,
        public int $alertsCount,
        public int $dnsChangesCount,
        public array $newSenderRoles,
        public array $domains,
        public array $brokenDns,
    ) {
    }
}
