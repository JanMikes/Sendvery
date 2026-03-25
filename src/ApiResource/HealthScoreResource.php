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
        public int $blacklistScore,
        public string $checkedAt,
    ) {
    }
}
