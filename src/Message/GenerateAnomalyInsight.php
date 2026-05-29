<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Dispatched when a report trips the failure-spike detector and a (non-muted)
 * alert is created. Routed to the async transport so the Anthropic call is
 * decoupled from report ingestion — a slow or failing API call never rolls back
 * the parse, and exhausted retries land in the `failed` transport.
 */
final readonly class GenerateAnomalyInsight
{
    public function __construct(
        public UuidInterface $reportId,
        public UuidInterface $teamId,
        public UuidInterface $alertId,
    ) {
    }
}
