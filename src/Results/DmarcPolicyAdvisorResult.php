<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\DmarcPolicy;

/**
 * Verdict from {@see \App\Services\DmarcPolicyAdvisor}. Tells the explainer
 * card what the current DMARC posture is, whether we'd recommend stepping up
 * to the next enforcement tier, and the plain-English reasoning to surface
 * to the user.
 *
 * The static factory encapsulates the eligibility rule per-tier so the
 * service stays a one-method thin wrapper and the rule lives next to the DTO
 * it produces.
 */
final readonly class DmarcPolicyAdvisorResult
{
    private const float NONE_TO_QUARANTINE_PASS_RATE_THRESHOLD = 90.0;
    private const int NONE_TO_QUARANTINE_MIN_REPORTS = 3;
    private const float QUARANTINE_TO_REJECT_PASS_RATE_THRESHOLD = 95.0;

    public function __construct(
        public DmarcPolicy $currentPolicy,
        public ?DmarcPolicy $recommendedNextPolicy,
        public bool $eligibleForNextTier,
        public string $reasonText,
        public float $passRate,
        public int $reportsCount,
    ) {
    }

    public static function forDomain(DmarcPolicy $current, float $passRate, int $reportsCount): self
    {
        // Reject is terminal — no "next tier" exists.
        if (DmarcPolicy::Reject === $current) {
            return new self(
                currentPolicy: $current,
                recommendedNextPolicy: null,
                eligibleForNextTier: false,
                reasonText: "You're at the strongest DMARC posture. Spoofed mail is blocked outright. Monitor for legitimate mail accidentally caught by enforcement.",
                passRate: $passRate,
                reportsCount: $reportsCount,
            );
        }

        // Pre-data state: the most important nudge here is "wait for data",
        // not the threshold copy, regardless of which tier we're at.
        if (0 === $reportsCount) {
            return new self(
                currentPolicy: $current,
                recommendedNextPolicy: self::nextTier($current),
                eligibleForNextTier: false,
                reasonText: 'No reports parsed yet — sender data will populate within 24 hours of publishing DMARC.',
                passRate: $passRate,
                reportsCount: $reportsCount,
            );
        }

        if (DmarcPolicy::None === $current) {
            if ($reportsCount < self::NONE_TO_QUARANTINE_MIN_REPORTS) {
                return new self(
                    currentPolicy: $current,
                    recommendedNextPolicy: DmarcPolicy::Quarantine,
                    eligibleForNextTier: false,
                    reasonText: sprintf(
                        'Need at least %d parsed reports before moving to p=quarantine — currently %d.',
                        self::NONE_TO_QUARANTINE_MIN_REPORTS,
                        $reportsCount,
                    ),
                    passRate: $passRate,
                    reportsCount: $reportsCount,
                );
            }

            if ($passRate >= self::NONE_TO_QUARANTINE_PASS_RATE_THRESHOLD) {
                return new self(
                    currentPolicy: $current,
                    recommendedNextPolicy: DmarcPolicy::Quarantine,
                    eligibleForNextTier: true,
                    reasonText: sprintf(
                        'Your pass rate is %.1f%% over %d reports — ready to begin gradual enforcement at p=quarantine.',
                        $passRate,
                        $reportsCount,
                    ),
                    passRate: $passRate,
                    reportsCount: $reportsCount,
                );
            }

            return new self(
                currentPolicy: $current,
                recommendedNextPolicy: DmarcPolicy::Quarantine,
                eligibleForNextTier: false,
                reasonText: 'Still collecting data. Move to p=quarantine once your pass rate stabilises above 90%.',
                passRate: $passRate,
                reportsCount: $reportsCount,
            );
        }

        // Quarantine → reject branch.
        if ($passRate >= self::QUARANTINE_TO_REJECT_PASS_RATE_THRESHOLD) {
            return new self(
                currentPolicy: $current,
                recommendedNextPolicy: DmarcPolicy::Reject,
                eligibleForNextTier: true,
                reasonText: sprintf(
                    'Your pass rate is %.1f%% over %d reports — ready to lock down with p=reject.',
                    $passRate,
                    $reportsCount,
                ),
                passRate: $passRate,
                reportsCount: $reportsCount,
            );
        }

        return new self(
            currentPolicy: $current,
            recommendedNextPolicy: DmarcPolicy::Reject,
            eligibleForNextTier: false,
            reasonText: 'Still collecting data. Move to p=reject once your pass rate stabilises above 95%.',
            passRate: $passRate,
            reportsCount: $reportsCount,
        );
    }

    private static function nextTier(DmarcPolicy $current): ?DmarcPolicy
    {
        return match ($current) {
            DmarcPolicy::None => DmarcPolicy::Quarantine,
            DmarcPolicy::Quarantine => DmarcPolicy::Reject,
            DmarcPolicy::Reject => null,
        };
    }
}
