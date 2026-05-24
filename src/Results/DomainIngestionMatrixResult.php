<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\IngestionPath;

/**
 * One row of the per-domain ingestion matrix on `/app/mailboxes`. Tells the
 * template which ingestion path (DNS / mailbox / mixed / none) a domain is on
 * and — when the path is "mailbox" — which mailbox row to link to so the user
 * can jump straight to the connection backing the reports.
 *
 * `lastReportAt` is the `processed_at` of the most recent parsed DMARC report
 * for the domain regardless of source, so a domain whose envelopes have all
 * been purged still surfaces its most-recent ingestion timestamp.
 */
final readonly class DomainIngestionMatrixResult
{
    public function __construct(
        public string $domainId,
        public string $domainName,
        public IngestionPath $path,
        public ?\DateTimeImmutable $lastReportAt,
        public ?string $mailboxId,
        public ?string $mailboxHost,
        public ?int $mailboxPort,
    ) {
    }

    /**
     * @param array{
     *     domain_id: string,
     *     domain_name: string,
     *     path: string,
     *     last_report_at: string|null,
     *     mailbox_id: string|null,
     *     mailbox_host: string|null,
     *     mailbox_port: int|string|null
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            path: IngestionPath::from($row['path']),
            lastReportAt: null === $row['last_report_at'] ? null : new \DateTimeImmutable($row['last_report_at']),
            mailboxId: $row['mailbox_id'],
            mailboxHost: $row['mailbox_host'],
            mailboxPort: null === $row['mailbox_port'] ? null : (int) $row['mailbox_port'],
        );
    }

    /**
     * True when the domain's ingestion is in the explicit "both" state — a
     * misconfiguration the user must resolve. The two valid paths (DNS only
     * or mailbox only) are mutually exclusive per domain; receiving via both
     * causes duplicate report ingestion and a confusing dashboard.
     */
    public function isMisconfigured(): bool
    {
        return IngestionPath::Mixed === $this->path;
    }
}
