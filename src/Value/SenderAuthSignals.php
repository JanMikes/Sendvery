<?php

declare(strict_types=1);

namespace App\Value;

/**
 * The per-IP authentication evidence a caller already has when it asks for a
 * sender identity (DEC-059 §3.3).
 *
 * These are deliberately *not* stored on `sender_identity`: authorization and
 * pass rates are per-team, per-domain judgements, while `sender_identity` is a
 * global cache of objective network facts. Callers that only know an IP can
 * omit them entirely and still get PTR-derived identity and role.
 *
 * Pass rates are percentages in the 0–100 range, matching the convention used
 * by the existing report queries and ReportFactsBuilder.
 */
final readonly class SenderAuthSignals
{
    public function __construct(
        public float $dkimPassRate,
        public float $spfPassRate,
        public bool $isAuthorized,
        public int $totalMessages,
    ) {
    }

    /**
     * Convenience factory for the common case where the caller has raw message
     * counts straight out of an aggregate query rather than percentages.
     */
    public static function fromCounts(
        int $dkimPassed,
        int $spfPassed,
        int $totalMessages,
        bool $isAuthorized = false,
    ): self {
        return new self(
            dkimPassRate: $totalMessages > 0 ? $dkimPassed / $totalMessages * 100 : 0.0,
            spfPassRate: $totalMessages > 0 ? $spfPassed / $totalMessages * 100 : 0.0,
            isAuthorized: $isAuthorized,
            totalMessages: $totalMessages,
        );
    }
}
