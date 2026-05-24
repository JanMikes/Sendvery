<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\Reports\MailboxEnvelopeStatus;

/**
 * One row in the recent-envelopes table on the mailbox detail page.
 *
 * `status` collapses the joined "is there a parsed dmarc_report or a
 * quarantine row?" question into a single enum so the template can pick the
 * right badge + deep-link without re-running the join. `targetReportId` is
 * the parsed-report or quarantine UUID we deep-link to; null for pending /
 * failed envelopes that produced neither artifact yet.
 */
final readonly class MailboxRecentEnvelope
{
    public function __construct(
        public string $envelopeId,
        public string $receivedAt,
        public string $fromAddress,
        public string $subject,
        public MailboxEnvelopeStatus $status,
        public ?string $targetReportId,
    ) {
    }

    /**
     * @param array{
     *     envelope_id: string,
     *     received_at: string,
     *     from_address: string,
     *     subject: string,
     *     report_id: string|null,
     *     quarantine_id: string|null,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        // Parsed reports win over quarantine rows — a re-process flow could in
        // theory have both, and the "you've got a clean report" surface is the
        // more valuable destination for the click.
        if (null !== $row['report_id']) {
            return new self(
                envelopeId: $row['envelope_id'],
                receivedAt: $row['received_at'],
                fromAddress: $row['from_address'],
                subject: $row['subject'],
                status: MailboxEnvelopeStatus::Parsed,
                targetReportId: $row['report_id'],
            );
        }

        if (null !== $row['quarantine_id']) {
            return new self(
                envelopeId: $row['envelope_id'],
                receivedAt: $row['received_at'],
                fromAddress: $row['from_address'],
                subject: $row['subject'],
                status: MailboxEnvelopeStatus::Quarantined,
                targetReportId: $row['quarantine_id'],
            );
        }

        return new self(
            envelopeId: $row['envelope_id'],
            receivedAt: $row['received_at'],
            fromAddress: $row['from_address'],
            subject: $row['subject'],
            status: MailboxEnvelopeStatus::Unparsed,
            targetReportId: null,
        );
    }
}
