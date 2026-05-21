<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DomainVerificationStatusResult
{
    public function __construct(
        public string $domainId,
        public string $domainName,
        public ?\DateTimeImmutable $spfVerifiedAt,
        public ?\DateTimeImmutable $dkimVerifiedAt,
        public ?\DateTimeImmutable $dmarcVerifiedAt,
        public ?\DateTimeImmutable $firstReportAt,
        public bool $dmarcCurrentlyValid,
    ) {
    }

    /**
     * @param array{
     *     domain_id: string,
     *     domain_name: string,
     *     spf_verified_at: string|null,
     *     dkim_verified_at: string|null,
     *     dmarc_verified_at: string|null,
     *     first_report_at: string|null,
     *     dmarc_currently_valid: bool|string|int|null
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            domainId: $row['domain_id'],
            domainName: $row['domain_name'],
            spfVerifiedAt: self::toDateTime($row['spf_verified_at']),
            dkimVerifiedAt: self::toDateTime($row['dkim_verified_at']),
            dmarcVerifiedAt: self::toDateTime($row['dmarc_verified_at']),
            firstReportAt: self::toDateTime($row['first_report_at']),
            // null means we've never run a check yet — treat as "not currently valid"
            // rather than guessing; the dmarcVerifiedAt timestamp already captures
            // "we have seen it valid at some point".
            dmarcCurrentlyValid: (bool) ($row['dmarc_currently_valid'] ?? false),
        );
    }

    private static function toDateTime(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable($value);
    }
}
