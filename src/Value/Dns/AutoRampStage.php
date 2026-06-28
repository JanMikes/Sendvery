<?php

declare(strict_types=1);

namespace App\Value\Dns;

use App\Value\DmarcPolicy;

/**
 * The rungs of the managed-DMARC auto-ramp ladder. The published policy
 * (`MonitoredDomain::managedPolicyP`) is the single source of truth for the
 * current stage — the evaluator and card always derive it via {@see fromPolicy()},
 * and rollback resets the stored stage to match. `Complete` is the terminal
 * state once `reject` has been reached and the goal is satisfied.
 */
enum AutoRampStage: string
{
    case Monitoring = 'monitoring';   // p=none
    case Quarantine = 'quarantine';   // p=quarantine pct=100
    case Reject = 'reject';           // p=reject pct=100
    case Complete = 'complete';       // terminal

    /** The next rung to tighten toward, or null at the top. */
    public function next(): ?self
    {
        return match ($this) {
            self::Monitoring => self::Quarantine,
            self::Quarantine => self::Reject,
            self::Reject => self::Complete,
            self::Complete => null,
        };
    }

    /** One rung down — the rollback / loosening target, or null at the bottom. */
    public function previous(): ?self
    {
        return match ($this) {
            self::Complete => self::Reject,
            self::Reject => self::Quarantine,
            self::Quarantine => self::Monitoring,
            self::Monitoring => null,
        };
    }

    /**
     * The policy that represents being AT this stage (Complete maps to reject).
     * Carries the customer's subdomain policy (`sp`) and coverage (`pct`) from
     * `$current` — only the top-level `p` changes per rung. Without this, an
     * explicit `sp=none` (subdomains intentionally exempt) or `pct<100` would be
     * silently dropped on the first ramp step, tightening enforcement against the
     * customer's stated intent.
     */
    public function targetPolicy(?ManagedDmarcPolicy $current = null): ManagedDmarcPolicy
    {
        $p = match ($this) {
            self::Monitoring => DmarcPolicy::None,
            self::Quarantine => DmarcPolicy::Quarantine,
            self::Reject, self::Complete => DmarcPolicy::Reject,
        };

        return new ManagedDmarcPolicy($p, $current?->sp, null !== $current ? $current->pct : 100);
    }

    /** Derive the stage from the currently published top-level policy. */
    public static function fromPolicy(?DmarcPolicy $p): self
    {
        return match ($p) {
            null, DmarcPolicy::None => self::Monitoring,
            DmarcPolicy::Quarantine => self::Quarantine,
            DmarcPolicy::Reject => self::Reject,
        };
    }
}
