<?php

declare(strict_types=1);

namespace App\Services;

use App\Value\DomainAttentionReason;

/**
 * What to say when a domain has published DMARC and no reports have arrived.
 *
 * THE DEFECT THIS EXISTS FOR: the message was a flat accusation — "no reports
 * have arrived after 48 hours, check that the rua= tag points at Sendvery" —
 * derived from nothing but the absence of reports. Absence has two causes and
 * that sentence only ever named one of them. A user whose DNS was already
 * correct got sent to re-verify it because our own poller was behind, which
 * costs them real time and teaches them to discount the next warning too.
 *
 * Split out of {@see DomainAttentionResolver} so the decision is a pure
 * function of one input and can be tested without standing up a dashboard.
 */
final readonly class NoReportsExplanation
{
    private function __construct(
        public string $label,
        public string $detail,
        public string $tone,
    ) {
    }

    /**
     * @param bool $isPipelineProvenHealthy whether OUR ingestion has provably
     *                                      succeeded recently. False also covers
     *                                      "never proven", which is the state of
     *                                      a fresh deployment — and is a reason
     *                                      to stay quiet, not to raise an alarm.
     */
    public static function forPipelineHealth(bool $isPipelineProvenHealthy): self
    {
        if ($isPipelineProvenHealthy) {
            return new self(
                label: 'No DMARC reports yet',
                detail: 'Your DMARC record is published and our report intake is running normally, so the likeliest remaining cause is the rua= tag — check that it points at Sendvery.',
                tone: 'warning',
            );
        }

        // Our side is unproven. The user is owed an explanation, not a task:
        // suppressing the accusation without replacing it leaves a blank that
        // reads as broken just the same.
        return new self(
            label: 'Waiting on our report intake',
            detail: "Your DMARC record is published and we haven't logged a successful report collection recently, so the delay looks like it is on our side rather than yours. There is nothing for you to do — we'll keep checking.",
            tone: 'info',
        );
    }

    public function toAttentionReason(): DomainAttentionReason
    {
        return new DomainAttentionReason(
            label: $this->label,
            detail: $this->detail,
            tone: $this->tone,
        );
    }
}
