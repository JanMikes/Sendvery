<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

use App\Results\PassRateTrendResult;
use App\Results\ReportDetailResult;
use App\Results\ReportSenderGroupResult;
use App\Services\Ai\Security\UntrustedDataSanitizer;

/**
 * Pure, deterministic computation of {@see ReportInsightFacts} from already-loaded
 * query results. No I/O — every value the LLM later narrates is decided here, in
 * PHP, so the model never does math, never invents a number, and only ever sees
 * sanitized untrusted strings.
 *
 * Split from {@see ReportInsightAnalyzer} (which does the DB loading) so this
 * logic — pass-rate math, forwarding-vs-spoofing classification, enforcement
 * readiness — is unit-testable with hand-built DTOs and no database.
 */
final readonly class ReportFactsBuilder
{
    private const int MAX_LISTED_SENDERS = 5;

    /** Forwarding signature: DKIM survives the hop, SPF breaks on the relay. */
    private const float FORWARDING_DKIM_MIN = 80.0;
    private const float FORWARDING_SPF_MAX = 30.0;

    /** A report is "essentially all aligned" at or above this pass rate. */
    private const float HIGH_PASS_RATE = 98.0;

    private const int READY_FOR_QUARANTINE_STREAK_DAYS = 14;
    private const int READY_FOR_REJECT_STREAK_DAYS = 30;

    public function __construct(
        private UntrustedDataSanitizer $sanitizer,
    ) {
    }

    /**
     * @param list<ReportSenderGroupResult> $senderGroups pre-sorted by volume desc (the query's ORDER BY)
     * @param list<PassRateTrendResult>     $trend        oldest → newest
     */
    public function build(ReportDetailResult $detail, array $senderGroups, array $trend): ReportInsightFacts
    {
        $totalMessages = 0;
        $passMessages = 0;
        $dkimOnlyFail = 0;
        $spfOnlyFail = 0;
        $bothFail = 0;
        $delivered = 0;
        $quarantined = 0;
        $rejected = 0;

        foreach ($detail->records as $record) {
            $count = $record->count;
            $totalMessages += $count;

            $dkimPass = 'pass' === $record->dkimResult;
            $spfPass = 'pass' === $record->spfResult;

            if ($dkimPass || $spfPass) {
                $passMessages += $count;
            }
            if (!$dkimPass && $spfPass) {
                $dkimOnlyFail += $count;
            } elseif ($dkimPass && !$spfPass) {
                $spfOnlyFail += $count;
            } elseif (!$dkimPass && !$spfPass) {
                $bothFail += $count;
            }

            if ('quarantine' === $record->disposition) {
                $quarantined += $count;
            } elseif ('reject' === $record->disposition) {
                $rejected += $count;
            } else {
                $delivered += $count;
            }
        }

        $passRate = $totalMessages > 0 ? round($passMessages / $totalMessages * 100, 1) : 100.0;

        $authorizedMessages = 0;
        $unknownMessages = 0;
        $topSenders = [];
        $forwardingSignals = [];
        $spoofingSignals = [];
        $unrecognizedSenders = [];

        foreach ($senderGroups as $group) {
            $isAuthorized = true === $group->senderIsAuthorized;

            if ($isAuthorized) {
                $authorizedMessages += $group->totalMessages;
            } else {
                $unknownMessages += $group->totalMessages;
            }

            $label = $this->sanitizer->sanitize($group->displayLabel);

            if (count($topSenders) < self::MAX_LISTED_SENDERS) {
                $topSenders[] = new SenderFact($label, $group->totalMessages, $group->dkimPassRate, $group->spfPassRate, $isAuthorized);
            }

            if ($group->dkimPassRate >= self::FORWARDING_DKIM_MIN
                && $group->spfPassRate <= self::FORWARDING_SPF_MAX
                && count($forwardingSignals) < self::MAX_LISTED_SENDERS
            ) {
                $forwardingSignals[] = new ForwardingSignal($label, $group->totalMessages, $group->dkimPassRate, $group->spfPassRate);
            }

            if (!$isAuthorized && $group->totalMessages > 0) {
                if (0.0 === $group->dkimPassRate && 0.0 === $group->spfPassRate
                    && count($spoofingSignals) < self::MAX_LISTED_SENDERS
                ) {
                    $spoofingSignals[] = new SpoofingSignal($label, $group->totalMessages, $group->dispositionNone > 0);
                }
                if (count($unrecognizedSenders) < self::MAX_LISTED_SENDERS) {
                    $unrecognizedSenders[] = new SenderFact($label, $group->totalMessages, $group->dkimPassRate, $group->spfPassRate, false);
                }
            }
        }

        $cleanStreakDays = $this->cleanStreakDays($trend);
        $readiness = $this->enforcementReadiness(
            $detail->policyP,
            $cleanStreakDays,
            [] !== $spoofingSignals,
            $unknownMessages,
            $passRate,
            $quarantined,
            $rejected,
        );

        return new ReportInsightFacts(
            reporterOrg: $this->sanitizer->sanitize($detail->reporterOrg, 80),
            protectedDomain: $this->sanitizer->sanitize($detail->policyDomain),
            windowDays: $this->windowDays($detail),
            totalMessages: $totalMessages,
            dmarcPassMessages: $passMessages,
            dmarcPassRate: $passRate,
            dkimOnlyFailMessages: $dkimOnlyFail,
            spfOnlyFailMessages: $spfOnlyFail,
            bothFailMessages: $bothFail,
            deliveredMessages: $delivered,
            quarantinedMessages: $quarantined,
            rejectedMessages: $rejected,
            authorizedMessages: $authorizedMessages,
            unknownMessages: $unknownMessages,
            distinctSenders: count($senderGroups),
            topSenders: $topSenders,
            forwardingSignals: $forwardingSignals,
            spoofingSignals: $spoofingSignals,
            unrecognizedSenders: $unrecognizedSenders,
            policy: $detail->policyP,
            subdomainPolicy: $detail->policySp,
            policyPct: $detail->policyPct,
            cleanStreakDays: $cleanStreakDays,
            enforcementReadiness: $readiness,
        );
    }

    private function windowDays(ReportDetailResult $detail): int
    {
        $seconds = (new \DateTimeImmutable($detail->dateRangeEnd))->getTimestamp()
            - (new \DateTimeImmutable($detail->dateRangeBegin))->getTimestamp();

        return max(1, (int) round($seconds / 86400));
    }

    /**
     * Consecutive most-recent days that saw traffic and zero failures. Days with
     * no observed mail are skipped (neither break nor count); the first
     * day-with-traffic-and-failures ends the streak.
     *
     * @param list<PassRateTrendResult> $trend oldest → newest
     */
    private function cleanStreakDays(array $trend): int
    {
        $streak = 0;

        for ($i = count($trend) - 1; $i >= 0; --$i) {
            $day = $trend[$i];
            $dayTotal = $day->passCount + $day->failCount;

            if (0 === $dayTotal) {
                continue;
            }
            if ($day->failCount > 0) {
                break;
            }
            ++$streak;
        }

        return $streak;
    }

    private function enforcementReadiness(
        string $policy,
        int $cleanStreakDays,
        bool $hasSpoofing,
        int $unknownMessages,
        float $passRate,
        int $quarantinedMessages,
        int $rejectedMessages,
    ): EnforcementReadiness {
        if ('reject' === $policy) {
            return EnforcementReadiness::AlreadyEnforcing;
        }

        if ('quarantine' === $policy) {
            return $cleanStreakDays >= self::READY_FOR_REJECT_STREAK_DAYS
                && !$hasSpoofing
                && 0 === $quarantinedMessages
                && 0 === $rejectedMessages
                    ? EnforcementReadiness::ReadyForReject
                    : EnforcementReadiness::AlreadyEnforcing;
        }

        // policy = none: monitoring only, no protection yet.
        if ($cleanStreakDays >= self::READY_FOR_QUARANTINE_STREAK_DAYS
            && !$hasSpoofing
            && 0 === $unknownMessages
            && $passRate >= self::HIGH_PASS_RATE
        ) {
            return EnforcementReadiness::ReadyForQuarantine;
        }

        return EnforcementReadiness::NotReady;
    }
}
