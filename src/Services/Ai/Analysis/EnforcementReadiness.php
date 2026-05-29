<?php

declare(strict_types=1);

namespace App\Services\Ai\Analysis;

/**
 * Deterministic verdict on whether a domain is ready to strengthen its DMARC
 * policy. Computed entirely in PHP from observed history; the LLM only narrates
 * the verdict, it never decides it.
 */
enum EnforcementReadiness: string
{
    /** Policy is already quarantine or reject — nothing to recommend. */
    case AlreadyEnforcing = 'already_enforcing';

    /** At p=quarantine, sustained clean → could move to p=reject. */
    case ReadyForReject = 'ready_for_reject';

    /** At p=none, sustained clean with no spoofing → could move to p=quarantine. */
    case ReadyForQuarantine = 'ready_for_quarantine';

    /** Not enough clean history, or unresolved failures/spoofing present. */
    case NotReady = 'not_ready';
}
