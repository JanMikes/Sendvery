<?php

declare(strict_types=1);

namespace App\Message;

use Ramsey\Uuid\UuidInterface;

/**
 * Dispatched after a ReceivedReportEmail row is persisted. Tells the worker
 * to parse the raw EML, extract DMARC XML, route each report to a team or
 * quarantine it, and update the envelope's processing status.
 */
final readonly class ProcessReceivedReportEmail
{
    public function __construct(
        public UuidInterface $envelopeId,
    ) {
    }
}
