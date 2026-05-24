<?php

declare(strict_types=1);

namespace App\Services;

use App\Query\GetDomainIngestionMatrix;
use App\Results\DomainIngestionMatrixResult;
use App\Services\Dns\RuaScenarioResolver;
use Ramsey\Uuid\Uuid;

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
        private RuaScenarioResolver $ruaScenarioResolver,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return list<DomainIngestionMatrixResult>
     */
    public function resolveForTeams(array $teamIds): array
    {
        $rows = $this->query->forTeams($teamIds);

        // TASK-100: enrich each matrix row with the RUA scenario derived from
        // the latest stored DMARC check so the template can render scenario-
        // aware badges + action CTAs without a second round of queries from
        // Twig. TODO: this introduces one extra query per domain (N+1) —
        // acceptable for a typical team's <20 domains; batch lookup is a
        // future task.
        return array_values(array_map(
            fn (DomainIngestionMatrixResult $row): DomainIngestionMatrixResult => $row->withScenario(
                $this->ruaScenarioResolver->resolveForDomainId(Uuid::fromString($row->domainId)),
            ),
            $rows,
        ));
    }
}
