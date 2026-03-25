<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DomainDetailResult
{
    public function __construct(
        public string $domainId,
        public string $domainName,
        public ?string $dmarcPolicy,
        public bool $isVerified,
        public string $createdAt,
        public int $totalReports,
        public int $totalMessages,
        public float $passRate,
        public int $uniqueSenders,
    ) {
    }

    /** @param array{domain_id: string, domain_name: string, dmarc_policy: string|null, is_verified: bool|string, created_at: string, total_reports: int|string, total_messages: int|string, pass_rate: float|string, unique_senders: int|string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            dmarcPolicy: $row['dmarc_policy'],
            isVerified: (bool) $row['is_verified'],
            createdAt: $row['created_at'],
            totalReports: (int) $row['total_reports'],
            totalMessages: (int) $row['total_messages'],
            passRate: (float) $row['pass_rate'],
            uniqueSenders: (int) $row['unique_senders'],
        );
    }
}
