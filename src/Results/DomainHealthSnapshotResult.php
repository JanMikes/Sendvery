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
        /**
         * Null when no blacklist check had run when this snapshot was written.
         *
         * This field was a non-nullable int fed by `(int) $row['blacklist_score']`,
         * which silently turned a NULL into 0 — so "we have never looked" and
         * "listed on every DNSBL we query" arrived at the template as the same
         * number, and the worse of the two won: a red bar at 0%. Exactly the
         * failure mode CLAUDE.md's mechanical tells describe.
         */
        public ?int $blacklistScore,
        public string $checkedAt,
        public array $recommendations,
        public ?string $shareHash,
    ) {
    }

    /** @param array{id: string, grade: string, score: int|string, spf_score: int|string, dkim_score: int|string, dmarc_score: int|string, mx_score: int|string, blacklist_score: int|string|null, checked_at: string, recommendations: string, share_hash: string|null} $row */
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
            // No cast fallback: NULL must survive as NULL, or the distinction
            // this column exists to carry is destroyed on the way out of SQL.
            blacklistScore: null === $row['blacklist_score'] ? null : (int) $row['blacklist_score'],
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
