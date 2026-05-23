<?php

declare(strict_types=1);

namespace App\Results;

final readonly class QuarantineDetailResult
{
    public function __construct(
        public string $quarantineId,
        public string $domainName,
        public string $reporterEmail,
        public string $reason,
        public string $quarantinedAt,
        public string $expiresAt,
        public string $subject,
        public int $sizeBytes,
        public string $envelopeId,
    ) {
    }

    /**
     * @param array{
     *     quarantine_id: string,
     *     domain_name: string,
     *     reporter_email: string,
     *     reason: string,
     *     quarantined_at: string,
     *     expires_at: string,
     *     subject: string,
     *     size_bytes: int|string,
     *     envelope_id: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            quarantineId: $row['quarantine_id'],
            domainName: $row['domain_name'],
            reporterEmail: $row['reporter_email'],
            reason: $row['reason'],
            quarantinedAt: $row['quarantined_at'],
            expiresAt: $row['expires_at'],
            subject: $row['subject'],
            sizeBytes: (int) $row['size_bytes'],
            envelopeId: $row['envelope_id'],
        );
    }
}
