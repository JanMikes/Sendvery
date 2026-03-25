<?php

declare(strict_types=1);

namespace App\Results;

final readonly class DomainHealthSnapshotResult
{
    /**
     * @param array<string, mixed> $recommendations
     */
    public function __construct(
        public string $id,
        public string $grade,
        public int $score,
        public int $spfScore,
        public int $dkimScore,
        public int $dmarcScore,
        public int $mxScore,
        public int $blacklistScore,
        public string $checkedAt,
        public array $recommendations,
        public ?string $shareHash,
    ) {
    }

    /** @param array{id: string, grade: string, score: int|string, spf_score: int|string, dkim_score: int|string, dmarc_score: int|string, mx_score: int|string, blacklist_score: int|string, checked_at: string, recommendations: string, share_hash: ?string} $row */
    public static function fromDatabaseRow(array $row): self
    {
        /** @var array<string, mixed> $recommendations */
        $recommendations = json_decode($row['recommendations'], true, flags: JSON_THROW_ON_ERROR);

        return new self(
            id: (string) $row['id'],
            grade: $row['grade'],
            score: (int) $row['score'],
            spfScore: (int) $row['spf_score'],
            dkimScore: (int) $row['dkim_score'],
            dmarcScore: (int) $row['dmarc_score'],
            mxScore: (int) $row['mx_score'],
            blacklistScore: (int) $row['blacklist_score'],
            checkedAt: $row['checked_at'],
            recommendations: $recommendations,
            shareHash: $row['share_hash'],
        );
    }

    public function gradeColor(): string
    {
        return match ($this->grade) {
            'A' => 'text-success',
            'B' => 'text-info',
            'C' => 'text-warning',
            'D' => 'text-error',
            default => 'text-error',
        };
    }
}
