<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\DomainProvider;

#[ApiResource(
    shortName: 'Domain',
    operations: [
        new GetCollection(
            uriTemplate: '/domains',
            provider: DomainProvider::class,
        ),
        new Get(
            uriTemplate: '/domains/{id}',
            provider: DomainProvider::class,
        ),
    ],
    routePrefix: '/api',
)]
final readonly class DomainResource
{
    public function __construct(
        public string $id,
        public string $domain,
        public ?string $dmarcPolicy,
        public bool $isVerified,
        public string $createdAt,
    ) {
    }
}
