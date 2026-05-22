<?php

declare(strict_types=1);

namespace App\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * Emitted by MonitoredDomain the first time a DMARC report is processed for
 * it. The handler triggers the celebratory "your first report just arrived"
 * email — a key activation moment that proves to the customer that
 * everything they set up actually works.
 */
final readonly class FirstReportArrivedForDomain
{
    public function __construct(
        public UuidInterface $domainId,
        public UuidInterface $teamId,
        public string $domainName,
        public string $reporterOrg,
    ) {
    }
}
