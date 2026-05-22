<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\Reports\EnvelopeProcessingStatus;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\UuidInterface;

/**
 * One row per email we receive in a reports inbox. Holds the raw RFC 822
 * source so we can re-parse if our extractor or parser changes, and tracks
 * processing status so we can audit what came in and what we did with it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'received_report_email')]
#[ORM\UniqueConstraint(name: 'uniq_envelope_source_msgid', columns: ['source', 'message_id'])]
#[ORM\Index(name: 'idx_envelope_status', columns: ['processing_status', 'ingested_at'])]
final class ReceivedReportEmail
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    public readonly UuidInterface $id;

    #[ORM\Column(type: 'string', length: 32, enumType: ReportSource::class)]
    public readonly ReportSource $source;

    #[ORM\ManyToOne(targetEntity: MailboxConnection::class)]
    #[ORM\JoinColumn(name: 'mailbox_connection_id', nullable: true, onDelete: 'SET NULL')]
    public readonly ?MailboxConnection $mailboxConnection;

    #[ORM\Column(type: 'bigint', nullable: true)]
    public readonly ?int $imapUidvalidity;

    #[ORM\Column(type: 'bigint', nullable: true)]
    public readonly ?int $imapUid;

    #[ORM\Column(type: 'text')]
    public readonly string $messageId;

    #[ORM\Column(type: 'text')]
    public readonly string $fromAddress;

    #[ORM\Column(type: 'text')]
    public readonly string $subject;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $receivedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $ingestedAt;

    #[ORM\Column(type: 'integer')]
    public readonly int $sizeBytes;

    #[ORM\Column(type: 'blob')]
    public readonly mixed $rawEml;

    #[ORM\Column(type: 'string', length: 32, enumType: EnvelopeProcessingStatus::class)]
    public EnvelopeProcessingStatus $processingStatus;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $processingError;

    #[ORM\Column(type: 'integer')]
    public int $attempts;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $processedAt;

    public function __construct(
        UuidInterface $id,
        ReportSource $source,
        string $messageId,
        string $fromAddress,
        string $subject,
        \DateTimeImmutable $receivedAt,
        \DateTimeImmutable $ingestedAt,
        int $sizeBytes,
        string $rawEml,
        ?MailboxConnection $mailboxConnection = null,
        ?int $imapUidvalidity = null,
        ?int $imapUid = null,
    ) {
        $this->id = $id;
        $this->source = $source;
        $this->mailboxConnection = $mailboxConnection;
        $this->imapUidvalidity = $imapUidvalidity;
        $this->imapUid = $imapUid;
        $this->messageId = $messageId;
        $this->fromAddress = $fromAddress;
        $this->subject = $subject;
        $this->receivedAt = $receivedAt;
        $this->ingestedAt = $ingestedAt;
        $this->sizeBytes = $sizeBytes;
        $this->rawEml = $rawEml;

        $this->processingStatus = EnvelopeProcessingStatus::Pending;
        $this->processingError = null;
        $this->attempts = 0;
        $this->processedAt = null;
    }

    public function markParsed(\DateTimeImmutable $processedAt): void
    {
        $this->processingStatus = EnvelopeProcessingStatus::Parsed;
        $this->processingError = null;
        $this->processedAt = $processedAt;
    }

    public function markQuarantined(\DateTimeImmutable $processedAt): void
    {
        $this->processingStatus = EnvelopeProcessingStatus::Quarantined;
        $this->processingError = null;
        $this->processedAt = $processedAt;
    }

    public function markFailed(string $error, \DateTimeImmutable $processedAt): void
    {
        $this->processingStatus = EnvelopeProcessingStatus::Failed;
        $this->processingError = $error;
        $this->processedAt = $processedAt;
    }

    public function markIgnored(string $reason, \DateTimeImmutable $processedAt): void
    {
        $this->processingStatus = EnvelopeProcessingStatus::Ignored;
        $this->processingError = $reason;
        $this->processedAt = $processedAt;
    }

    public function incrementAttempts(): void
    {
        ++$this->attempts;
    }

    /**
     * Convenience accessor — Doctrine returns the BYTEA column as a resource
     * stream that callers must read to get the bytes.
     */
    public function rawEmlBytes(): string
    {
        if (is_resource($this->rawEml)) {
            $contents = stream_get_contents($this->rawEml);
            assert(false !== $contents);
            rewind($this->rawEml);

            return $contents;
        }

        assert(is_string($this->rawEml));

        return $this->rawEml;
    }
}
