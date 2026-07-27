<?php

declare(strict_types=1);

namespace App\Value;

/**
 * What happened to mail a forwarder relayed (DEC-060 WP-E).
 *
 * There is no `Failure` case and no severity attached to any of these, and that
 * is the point. Six real messages are sitting in spam folders right now because
 * a recipient-side gateway rewrote their bodies; the domain owner did not cause
 * it and cannot fix it. CLAUDE.md's rule is *"Unknown Is Not Failure"* — this
 * enum is its corollary: **explained is not broken**. A held-back forward is a
 * cost of forwarding that has been accounted for, not a defect in the domain's
 * setup, and rendering it in the same tone as a real fault is what teaches a
 * user to stop reading warnings.
 */
enum ForwardedMailOutcome: string
{
    /** Not a forwarder, or a forwarder whose mail all got through. */
    case NothingToExplain = 'nothing_to_explain';

    /** The receiver applied the domain's policy and filed the mail as spam. */
    case Quarantined = 'quarantined';

    /**
     * The receiver refused the mail outright. Harsher for the recipient — they
     * never saw it — but no more the sender's fault than a quarantine is.
     */
    case Rejected = 'rejected';
}
