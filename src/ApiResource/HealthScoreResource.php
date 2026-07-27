<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\HealthScoreProvider;

#[ApiResource(
    shortName: 'HealthScore',
    operations: [
        new GetCollection(
            uriTemplate: '/health-scores',
            provider: HealthScoreProvider::class,
        ),
        new Get(
            uriTemplate: '/health-scores/{id}',
            provider: HealthScoreProvider::class,
        ),
    ],
    routePrefix: '/api',
)]
final readonly class HealthScoreResource
{
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
         * This was a non-nullable int fed by `(int) $row['blacklist_score']`,
         * so a NULL reached API consumers as `0`. On a 0-100 scale where 100
         * means clean, 0 is the exact value meaning "listed on every DNSBL we
         * query" — an integrator would have read a totally unmeasured domain as
         * a catastrophically blacklisted one.
         */
        public ?int $blacklistScore,
        public string $checkedAt,
    ) {
    }

    /**
     * Row-to-resource mapping lives here, following the `fromDatabaseRow()`
     * convention the `src/Results/` DTOs already use, so the null-preserving
     * behaviour is testable without standing up API Platform's stateless
     * firewall.
     *
     * @param array<string, mixed> $row a `domain_health_snapshot` row as DBAL returns it
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            grade: (string) $row['grade'],
            score: (int) $row['score'],
            spfScore: (int) $row['spf_score'],
            dkimScore: (int) $row['dkim_score'],
            dmarcScore: (int) $row['dmarc_score'],
            mxScore: (int) $row['mx_score'],
            // No cast fallback: NULL must survive as NULL.
            blacklistScore: null === $row['blacklist_score'] ? null : (int) $row['blacklist_score'],
            checkedAt: (string) $row['checked_at'],
        );
    }
}
