<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Re-runs ingestion on a single quarantined row's underlying envelope so the
 * team can manually rescue a report after fixing whatever blocked it (DNS
 * verification, plan upgrade, etc.). The handler is idempotent — a missing
 * row is a silent no-op so racing tabs don't error out.
 */
final readonly class ReprocessQuarantinedReport
{
    public function __construct(
        public UuidInterface $quarantineId,
        public UuidInterface $teamId,
    ) {
    }
}
