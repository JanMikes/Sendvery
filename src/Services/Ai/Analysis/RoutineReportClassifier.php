<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

use App\Services\Ai\Result\OnDemandExplanationResult;

/**
 * The cost lever behind DEC-055's observation that ~95% of reports are routine:
 * when a report is unambiguously clean, we return a templated explanation built
 * in PHP and make NO API call — zero tokens, zero latency, zero injection
 * surface. The LLM is only spent on reports that actually have something to say.
 */
final readonly class RoutineReportClassifier
{
    private const float HIGH_PASS_RATE = 98.0;

    /**
     * Routine = essentially everything aligned, nothing blocked, no spoofing,
     * and every sending source already recognised. All five must hold.
     */
    public function isRoutine(ReportInsightFacts $facts): bool
    {
        return $facts->dmarcPassRate >= self::HIGH_PASS_RATE
            && [] === $facts->spoofingSignals
            && 0 === $facts->quarantinedMessages
            && 0 === $facts->rejectedMessages
            && 0 === $facts->unknownMessages;
    }

    /**
     * Plain-language copy for a routine report, assembled from the facts with no
     * LLM call. Written in the same calm, second-person voice the model is told
     * to use, so the experience is consistent whether or not the model runs.
     */
    public function buildTemplatedExplanation(ReportInsightFacts $facts): OnDemandExplanationResult
    {
        $text = sprintf(
            'This is a routine report from %s, covering the last %d day%s of email sent using %s. '
            .'All %s message%s passed DMARC alignment — the check that proves the mail genuinely came from your domain — '
            .'and every sending source is one you already recognise. Nothing was quarantined or rejected. No action is needed.',
            $facts->reporterOrg,
            $facts->windowDays,
            1 === $facts->windowDays ? '' : 's',
            $facts->protectedDomain,
            number_format($facts->totalMessages),
            1 === $facts->totalMessages ? '' : 's',
        );

        return new OnDemandExplanationResult($text);
    }
}
