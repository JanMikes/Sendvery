<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\ReportProvider;

#[ApiResource(
    shortName: 'Report',
    operations: [
        new GetCollection(
            uriTemplate: '/reports',
            provider: ReportProvider::class,
        ),
        new Get(
            uriTemplate: '/reports/{id}',
            provider: ReportProvider::class,
        ),
    ],
    routePrefix: '/api',
)]
final readonly class ReportResource
{
    public function __construct(
        public string $id,
        public string $domainId,
        public string $reporterOrg,
        public string $dateRangeBegin,
        public string $dateRangeEnd,
        public string $policyDomain,
        public string $processedAt,
    ) {
    }
}
