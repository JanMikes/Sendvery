<?php

declare(strict_types=1);

namespace App\Value;

/**
 * One sender the weekly digest reports as new, already collapsed to a single
 * identity (DEC-059 §3.2, D5).
 *
 * The unit is the *sender*, never the address. Seznam's rotating IPv6 pool is
 * fifteen machines and one relay; the digest that triggered DEC-059 printed all
 * fifteen as separate discoveries, which is how a customer ended up staring at
 * a wall of IPv6 addresses. {@see $label} is therefore the grouped name and
 * {@see $messageCount} the group's combined volume.
 *
 * {@see $role} travels with it because a recipient-side gateway breaks SPF by
 * design: without saying what a sender *is*, "new sender, 2 messages, 0% pass"
 * reads as an attack when it is somebody's mail being forwarded.
 */
final readonly class WeeklyDigestNewSender
{
    public function __construct(
        /** Grouped, human-facing name — an organisation or registrable domain, and a raw IP only as a last resort. */
        public string $label,
        public SenderRole $role,
        public int $messageCount,
        public int $passedMessageCount,
    ) {
    }

    /**
     * Null only when the reporter described this sender with no messages at
     * all. There is nothing to take a percentage of, and printing 0% would
     * accuse a sender of failing mail it never sent.
     */
    public function passRate(): ?float
    {
        return $this->messageCount > 0
            ? $this->passedMessageCount / $this->messageCount * 100
            : null;
    }
}
