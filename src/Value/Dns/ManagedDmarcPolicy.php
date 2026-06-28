<?php

declare(strict_types=1);

namespace App\Value\Dns;

use App\Value\DmarcPolicy;

/**
 * The customer-facing DMARC policy Sendvery hosts on the managed record: the
 * top-level policy (`p`), an optional subdomain policy (`sp`), and coverage
 * (`pct`). `rua` is NOT part of this object — the composer always sets it to
 * Sendvery's report address (DEC-058a). `pct` is carried so finer ramp steps
 * (e.g. quarantine pct=25) can be added later without a migration; v1 ramps at
 * pct=100 per tier.
 */
final readonly class ManagedDmarcPolicy
{
    public function __construct(
        public DmarcPolicy $p,
        public ?DmarcPolicy $sp = null,
        public int $pct = 100,
    ) {
    }

    public static function monitoring(): self
    {
        return new self(DmarcPolicy::None);
    }

    /**
     * Drives the idempotent republish path: a policy change only republishes
     * (and writes an audit row) when the effective content differs.
     */
    public function equals(self $other): bool
    {
        return $this->p === $other->p
            && $this->sp === $other->sp
            && $this->pct === $other->pct;
    }
}
