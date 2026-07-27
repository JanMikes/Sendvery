<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Asks the worker to hand back reports we parked because this team had no
 * monthly report headroom left, now that some has returned.
 *
 * Dispatched from the two places capacity actually comes back: an upgrade
 * (UpgradeTeamPlanHandler — a bigger cap) and the monthly period rolling
 * (`sendvery:usage:reset` — the counter zeroes). Idempotent and safe to
 * over-dispatch: the handler re-reads the real headroom and releases at most
 * that many.
 *
 * Routed to `async` — a backlog can be thousands of reports, and the upgrade
 * path runs inside a Stripe webhook that has to answer immediately.
 */
final readonly class ReleaseQuarantinedReportsForTeam
{
    public function __construct(
        public UuidInterface $teamId,
    ) {
    }
}
