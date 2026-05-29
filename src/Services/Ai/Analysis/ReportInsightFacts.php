<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * The compact, fully-pre-computed and sanitized fact pack for a single DMARC
 * report. Everything here is derived deterministically in PHP by
 * {@see ReportInsightAnalyzer}; the LLM narrates these values and never
 * computes or invents one.
 *
 * Property order is fixed so `json_encode($facts)` is byte-stable — important
 * because the serialized facts ride in the (uncached) user turn while the
 * system prompt stays in the cached prefix.
 */
final readonly class ReportInsightFacts
{
    /**
     * @param list<SenderFact>       $topSenders          highest-volume sources, sanitized
     * @param list<ForwardingSignal> $forwardingSignals   likely-legitimate forwarding sources
     * @param list<SpoofingSignal>   $spoofingSignals     possible spoofing sources
     * @param list<SenderFact>       $unrecognizedSenders unauthorized sources worth surfacing
     */
    public function __construct(
        public string $reporterOrg,
        public string $protectedDomain,
        public int $windowDays,
        public int $totalMessages,
        public int $dmarcPassMessages,
        public float $dmarcPassRate,
        public int $dkimOnlyFailMessages,
        public int $spfOnlyFailMessages,
        public int $bothFailMessages,
        public int $deliveredMessages,
        public int $quarantinedMessages,
        public int $rejectedMessages,
        public int $authorizedMessages,
        public int $unknownMessages,
        public int $distinctSenders,
        public array $topSenders,
        public array $forwardingSignals,
        public array $spoofingSignals,
        public array $unrecognizedSenders,
        public string $policy,
        public ?string $subdomainPolicy,
        public int $policyPct,
        public int $cleanStreakDays,
        public EnforcementReadiness $enforcementReadiness,
    ) {
    }
}
