<?php

declare(strict_types=1);

namespace App\Value;

/**
 * The three genuinely different things "is report intake working?" can mean.
 *
 * A bool cannot carry this. `false` would collapse "our poller has been stuck
 * for six hours" into the same answer as "this deployment has never polled" —
 * and those demand opposite responses: the first is an incident, the second is
 * a system that has simply not run yet, which is the state of every fresh
 * install and every local dev environment where the central inbox is not
 * configured at all. Painting the second red is CLAUDE.md's "unknown is not
 * failure" broken on the very surface built to explain unknowns.
 */
enum IngestionIntakeState: string
{
    case Healthy = 'healthy';
    case Stale = 'stale';
    case NeverPolled = 'never_polled';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Running normally',
            self::Stale => 'Has not completed recently',
            self::NeverPolled => 'Not checked yet',
        };
    }

    /**
     * Only a genuinely stuck pipeline earns a warning tone. NeverPolled is
     * neutral on purpose — see the class docblock.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Healthy => 'success',
            self::Stale => 'warning',
            self::NeverPolled => 'neutral',
        };
    }

    /**
     * Whether an operator has something to do. Kept separate from tone so a
     * future surface cannot accidentally imply action by choosing a colour.
     */
    public function needsOperatorAttention(): bool
    {
        return self::Stale === $this;
    }
}
