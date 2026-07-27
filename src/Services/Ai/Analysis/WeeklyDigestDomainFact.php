<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * One domain's week, pre-computed and sanitized, for the weekly-digest prompt.
 */
final readonly class WeeklyDigestDomainFact
{
    /**
     * @param list<SenderRoleCount> $newSenderRoles breakdown of $newSenderCount by what each sender is
     */
    public function __construct(
        public string $domain,
        public int $messages,
        /**
         * Null when no DMARC records landed in the window. Serialised into the
         * prompt as `null` so the model narrates "no reports yet" instead of
         * inventing a 0% failure.
         */
        public ?float $passRate,
        public ?float $passRateDelta,
        public int $newSenderCount,
        /**
         * Without this the model saw "3 new senders, 4% of mail failing" and
         * faithfully recommended fixing misconfigured sending sources — when
         * all three were third-party forwarders and nothing was misconfigured
         * (DEC-059 D10).
         */
        public array $newSenderRoles,
        public int $alertCount,
    ) {
    }
}
