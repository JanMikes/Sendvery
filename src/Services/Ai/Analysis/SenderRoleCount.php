<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

use App\Value\SenderRole;

/**
 * How many newly discovered senders of one role a domain — or the whole team —
 * saw this week.
 *
 * Counts and a closed enum, never a name. {@see WeeklyDigestFacts} must not
 * carry attacker-influenceable free text into the prompt, and a sender's
 * hostname is supplied by whoever runs the sending host. `SenderRole` has five
 * fixed cases and `count` is an integer, so nothing an outside party controls
 * can reach the model through this class.
 */
final readonly class SenderRoleCount
{
    public function __construct(
        public SenderRole $role,
        public int $count,
    ) {
    }
}
