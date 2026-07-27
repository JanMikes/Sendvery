<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DnsHealthOverviewResult
{
    /**
     * `$spfCheckValid` / `$dkimCheckValid` / `$dmarcCheckValid` /
     * `$mxCheckValid` carry the verdict of the NEWEST stored `dns_check_result`
     * row for that protocol — three states, deliberately `?bool`:
     *   true  → the latest check found a valid record
     *   false → the latest check ran and the record is missing or broken
     *   null  → no check row exists for this protocol yet (never checked).
     *
     * They are the authoritative answer to "is this record healthy right now?".
     * The `*VerifiedAt` timestamps beside them are NOT: `CheckDomainDnsHandler`
     * only ever SETS them, so a record that broke last month still carries a
     * verified-at from when it last worked. Reading the timestamps let a domain
     * with dead SPF classify as fully healthy and disappear from triage. Keep
     * `*VerifiedAt` for what it is actually for (verification/quarantine-release
     * history) and read these for health.
     */
    public function __construct(
        public string $domainId,
        public string $domainName,
        public ?\DateTimeImmutable $spfVerifiedAt,
        public ?\DateTimeImmutable $dkimVerifiedAt,
        public ?\DateTimeImmutable $dmarcVerifiedAt,
        public ?string $latestSnapshotGrade,
        public ?int $latestSnapshotScore,
        public ?int $latestSpfScore,
        public ?int $latestDkimScore,
        public ?int $latestDmarcScore,
        public ?int $latestMxScore,
        public ?\DateTimeImmutable $latestCheckedAt,
        public ?bool $spfCheckValid = null,
        public ?bool $dkimCheckValid = null,
        public ?bool $dmarcCheckValid = null,
        public ?bool $mxCheckValid = null,
    ) {
    }

    /**
     * @param array{
     *     domain_id: string,
     *     domain_name: string,
     *     spf_verified_at: string|null,
     *     dkim_verified_at: string|null,
     *     dmarc_verified_at: string|null,
     *     latest_snapshot_grade: string|null,
     *     latest_snapshot_score: int|string|null,
     *     latest_spf_score: int|string|null,
     *     latest_dkim_score: int|string|null,
     *     latest_dmarc_score: int|string|null,
     *     latest_mx_score: int|string|null,
     *     latest_checked_at: string|null,
     *     spf_check_valid?: bool|int|string|null,
     *     dkim_check_valid?: bool|int|string|null,
     *     dmarc_check_valid?: bool|int|string|null,
     *     mx_check_valid?: bool|int|string|null
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
            latestSnapshotGrade: $row['latest_snapshot_grade'],
            latestSnapshotScore: self::toInt($row['latest_snapshot_score']),
            latestSpfScore: self::toInt($row['latest_spf_score']),
            latestDkimScore: self::toInt($row['latest_dkim_score']),
            latestDmarcScore: self::toInt($row['latest_dmarc_score']),
            latestMxScore: self::toInt($row['latest_mx_score']),
            latestCheckedAt: self::toDateTime($row['latest_checked_at']),
            spfCheckValid: self::toNullableBool($row['spf_check_valid'] ?? null),
            dkimCheckValid: self::toNullableBool($row['dkim_check_valid'] ?? null),
            dmarcCheckValid: self::toNullableBool($row['dmarc_check_valid'] ?? null),
            mxCheckValid: self::toNullableBool($row['mx_check_valid'] ?? null),
        );
    }

    public function isSpfVerified(): bool
    {
        return null !== $this->spfVerifiedAt;
    }

    public function isDkimVerified(): bool
    {
        return null !== $this->dkimVerifiedAt;
    }

    public function isDmarcVerified(): bool
    {
        return null !== $this->dmarcVerifiedAt;
    }

    public function hasSnapshot(): bool
    {
        return null !== $this->latestSnapshotGrade;
    }

    /**
     * Tailwind utility class for the grade letter colour. Guard at the call
     * site with {@see hasSnapshot()} so the default arm is never the
     * load-bearing branch — but the default still falls back to "text-error"
     * so a missing/unexpected grade renders as a safe red rather than
     * crashing the template.
     */
    public function snapshotGradeColor(): string
    {
        return match ($this->latestSnapshotGrade) {
            'A' => 'text-success',
            'B' => 'text-info',
            'C' => 'text-warning',
            default => 'text-error',
        };
    }

    private static function toDateTime(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable($value);
    }

    private static function toInt(int|string|null $value): ?int
    {
        return null === $value ? null : (int) $value;
    }

    /**
     * Postgres booleans surface as real bools on some driver builds and as
     * `'t'`/`'f'` on others; null must survive as null because it is the
     * "never checked" state, not a false.
     */
    private static function toNullableBool(bool|int|string|null $value): ?bool
    {
        if (null === $value) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 't', 'true', 'TRUE'], true);
    }
}
