<?php

declare(strict_types=1);

namespace App\Services;

use App\Query\GetDomainIngestionMatrix;
use App\Results\DomainIngestionMatrixResult;

/**
 * Thin testable wrapper around {@see GetDomainIngestionMatrix}. Lives as a
 * service so controllers autowire a single typed entry point and future
 * adjustments (logging, eligibility-aware filtering, etc.) have a home
 * without rippling through call sites.
 */
final readonly class IngestionPathResolver
{
    public function __construct(
        private GetDomainIngestionMatrix $query,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return list<DomainIngestionMatrixResult>
     */
    public function resolveForTeams(array $teamIds): array
    {
        return $this->query->forTeams($teamIds);
    }
}
