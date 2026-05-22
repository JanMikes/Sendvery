<?php

declare(strict_types=1);

namespace App\Value\Reports;

/**
 * One email pulled from the central inbox, ready to be persisted as a
 * ReceivedReportEmail. Holds the raw RFC 822 source plus the IMAP UID we
 * need to move the message to a per-status folder after processing.
 */
final readonly class FetchedEnvelope
{
    public function __construct(
        public string $messageId,
        public string $fromAddress,
        public string $subject,
        public \DateTimeImmutable $receivedAt,
        public string $rawEml,
        public int $uid,
        public ?int $uidvalidity,
    ) {
    }
}
