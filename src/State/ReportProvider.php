<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ReportResource;
use App\Services\DashboardContext;
use Doctrine\DBAL\Connection;

/** @implements ProviderInterface<ReportResource> */
final readonly class ReportProvider implements ProviderInterface
{
    public function __construct(
        private Connection $database,
        private DashboardContext $dashboardContext,
    ) {
    }

    /** @return ReportResource|array<ReportResource>|null */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReportResource|array|null
    {
        $teamId = $this->dashboardContext->getTeamId()->toString();

        if (isset($uriVariables['id'])) {
            return $this->getOne((string) $uriVariables['id'], $teamId);
        }

        return $this->getAll($teamId);
    }

    private function getOne(string $id, string $teamId): ?ReportResource
    {
        $row = $this->database->executeQuery(
            'SELECT dr.id, dr.monitored_domain_id, dr.reporter_org, dr.date_range_begin, dr.date_range_end, dr.policy_domain, dr.processed_at
             FROM dmarc_report dr
             JOIN monitored_domain md ON md.id = dr.monitored_domain_id
             WHERE dr.id = :id AND md.team_id = :teamId',
            ['id' => $id, 'teamId' => $teamId],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return array<ReportResource>
     */
    private function getAll(string $teamId): array
    {
        $rows = $this->database->executeQuery(
            'SELECT dr.id, dr.monitored_domain_id, dr.reporter_org, dr.date_range_begin, dr.date_range_end, dr.policy_domain, dr.processed_at
             FROM dmarc_report dr
             JOIN monitored_domain md ON md.id = dr.monitored_domain_id
             WHERE md.team_id = :teamId
             ORDER BY dr.processed_at DESC
             LIMIT 100',
            ['teamId' => $teamId],
        )->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): ReportResource
    {
        return new ReportResource(
            id: (string) $row['id'],
            domainId: (string) $row['monitored_domain_id'],
            reporterOrg: (string) $row['reporter_org'],
            dateRangeBegin: (string) $row['date_range_begin'],
            dateRangeEnd: (string) $row['date_range_end'],
            policyDomain: (string) $row['policy_domain'],
            processedAt: (string) $row['processed_at'],
        );
    }
}
