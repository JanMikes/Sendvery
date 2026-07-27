<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\IngestionIntakeState;

final readonly class IngestionIntakeHealthResult
{
    public function __construct(
        public IngestionIntakeState $state,
        /**
         * Null when the pipeline has never completed a poll. Deliberately not
         * defaulted to any timestamp: "never" is not a very old success, and a
         * surface that renders an invented date invites an operator to reason
         * about a run that did not happen.
         */
        public ?\DateTimeImmutable $lastSuccessAt,
    ) {
    }

    /**
     * The sentence shown to an operator. Written here rather than in Twig so
     * the wording for each state is testable without rendering a page, and so
     * "never polled" cannot drift into sounding like a failure in one template
     * while reading as neutral in another.
     */
    public function explanation(): string
    {
        return match ($this->state) {
            IngestionIntakeState::Healthy => 'Report intake is running normally.',
            IngestionIntakeState::Stale => 'Report intake has not completed a successful collection recently. Reports may be delayed — this is on our side, not the customer\'s.',
            IngestionIntakeState::NeverPolled => 'Report intake has not checked in yet. On a new install this is expected until the first scheduled run.',
        };
    }
}
