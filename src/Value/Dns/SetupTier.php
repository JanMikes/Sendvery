<?php

declare(strict_types=1);

namespace App\Value\Dns;

/**
 * The three buckets the guided DNS setup surface groups its steps into.
 *
 * WHY three and not "pass/fail": the old flat "N of 5 checks passing" checklist
 * made every unfinished row look equally urgent, so a user staring at four red
 * rows could not tell which one to touch first — the reported complaint was
 * literally "it is misleading and i do not fully understand what should i do".
 * Splitting "do this now" from "you'll need to do this, but not from here" from
 * "already done" answers that in one glance.
 *
 * Only ONE step is ever in {@see self::ActionRequired}: the guided surface hands
 * the user a single record to paste, then re-checks. Anything else that is not
 * finished waits in {@see self::Later}.
 */
enum SetupTier: string
{
    case ActionRequired = 'action_required';
    case Later = 'later';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::ActionRequired => 'Action required now',
            self::Later => 'Waiting on you later',
            self::Done => 'Done',
        };
    }

    /**
     * One-line explanation of what belongs in the tier, shown under the heading
     * so the grouping is self-explanatory rather than a label the user has to
     * infer.
     */
    public function description(): string
    {
        return match ($this) {
            self::ActionRequired => 'Do this one thing next — we check again automatically.',
            self::Later => 'Not blocking your reports. Come back to these when you can.',
            self::Done => 'Nothing to do here.',
        };
    }

    /**
     * daisyUI semantic colour token for the tier heading + rail.
     */
    public function tone(): string
    {
        return match ($this) {
            self::ActionRequired => 'warning',
            self::Later => 'info',
            self::Done => 'success',
        };
    }
}
