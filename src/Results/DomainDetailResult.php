<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DomainDetailResult
{
    public function __construct(
        public string $domainId,
        public string $domainName,
        public ?string $dmarcPolicy,
        public ?string $spfVerifiedAt,
        public ?string $dkimVerifiedAt,
        public ?string $dmarcVerifiedAt,
        public ?string $firstReportAt,
        public string $createdAt,
        public int $totalReports,
        public int $totalMessages,
        public float $passRate,
        public int $uniqueSenders,
        public ?string $dkimSelector,
    ) {
    }

    public function isVerified(): bool
    {
        return null !== $this->dmarcVerifiedAt;
    }

    /** @param array{domain_id: string, domain_name: string, dmarc_policy: string|null, spf_verified_at: string|null, dkim_verified_at: string|null, dmarc_verified_at: string|null, first_report_at: string|null, created_at: string, total_reports: int|string, total_messages: int|string, pass_rate: float|string, unique_senders: int|string, dkim_selector: string|null} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            dmarcPolicy: $row['dmarc_policy'],
            spfVerifiedAt: $row['spf_verified_at'],
            dkimVerifiedAt: $row['dkim_verified_at'],
            dmarcVerifiedAt: $row['dmarc_verified_at'],
            firstReportAt: $row['first_report_at'],
            createdAt: $row['created_at'],
            totalReports: (int) $row['total_reports'],
            totalMessages: (int) $row['total_messages'],
            passRate: (float) $row['pass_rate'],
            uniqueSenders: (int) $row['unique_senders'],
            dkimSelector: $row['dkim_selector'],
        );
    }
}
