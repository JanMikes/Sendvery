<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\ForwardedMailOutcome;

/**
 * The plain account of why a forwarder's mail did not get delivered
 * (DEC-060 WP-E), produced by {@see \App\Services\ForwardedMailExplainer}.
 *
 * Three fields rather than one blob because they answer three different
 * questions and only the first two are ever true of a fault: what happened, why
 * it happened, and what — if anything — the reader should do. The third field
 * is the one this work package exists for. Its honest content is "nothing", and
 * saying that out loud is more useful than a recommendation invented to fill
 * the space.
 */
final readonly class ForwardedMailExplanation
{
    public function __construct(
        public ForwardedMailOutcome $outcome,
        /** Messages the receiver did not deliver: quarantined plus rejected. */
        public int $affectedMessages,
        public string $headline,
        public string $whyItHappened,
        public string $whatYouCanDo,
    ) {
    }

    public static function nothingToExplain(): self
    {
        return new self(
            outcome: ForwardedMailOutcome::NothingToExplain,
            affectedMessages: 0,
            headline: '',
            whyItHappened: '',
            whatYouCanDo: '',
        );
    }

    /**
     * Whether the reader should be shown anything at all. The caller renders
     * this for every sender on the pane and lets the result decide, the same
     * way {@see SenderAdvisorResult} works.
     */
    public function isWorthSaying(): bool
    {
        return ForwardedMailOutcome::NothingToExplain !== $this->outcome;
    }
}
