<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DnsHealthOverviewResult
{
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
     *     latest_checked_at: string|null
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
}
