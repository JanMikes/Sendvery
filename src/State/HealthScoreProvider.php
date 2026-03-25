<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\HealthScoreResource;
use App\Services\DashboardContext;
use Doctrine\DBAL\Connection;

/** @implements ProviderInterface<HealthScoreResource> */
final readonly class HealthScoreProvider implements ProviderInterface
{
    public function __construct(
        private Connection $database,
        private DashboardContext $dashboardContext,
    ) {
    }

    /** @return HealthScoreResource|array<HealthScoreResource>|null */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HealthScoreResource|array|null
    {
        $teamId = $this->dashboardContext->getTeamId()->toString();

        if (isset($uriVariables['id'])) {
            return $this->getOne((string) $uriVariables['id'], $teamId);
        }

        return $this->getAll($teamId);
    }

    private function getOne(string $id, string $teamId): ?HealthScoreResource
    {
        $row = $this->database->executeQuery(
            'SELECT dhs.id, dhs.grade, dhs.score, dhs.spf_score, dhs.dkim_score, dhs.dmarc_score, dhs.mx_score, dhs.blacklist_score, dhs.checked_at
             FROM domain_health_snapshot dhs
             JOIN monitored_domain md ON md.id = dhs.monitored_domain_id
             WHERE dhs.id = :id AND md.team_id = :teamId',
            ['id' => $id, 'teamId' => $teamId],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return array<HealthScoreResource>
     */
    private function getAll(string $teamId): array
    {
        $rows = $this->database->executeQuery(
            'SELECT dhs.id, dhs.grade, dhs.score, dhs.spf_score, dhs.dkim_score, dhs.dmarc_score, dhs.mx_score, dhs.blacklist_score, dhs.checked_at
             FROM domain_health_snapshot dhs
             JOIN monitored_domain md ON md.id = dhs.monitored_domain_id
             WHERE md.team_id = :teamId
             ORDER BY dhs.checked_at DESC
             LIMIT 100',
            ['teamId' => $teamId],
        )->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): HealthScoreResource
    {
        return new HealthScoreResource(
            id: (string) $row['id'],
            grade: (string) $row['grade'],
            score: (int) $row['score'],
            spfScore: (int) $row['spf_score'],
            dkimScore: (int) $row['dkim_score'],
            dmarcScore: (int) $row['dmarc_score'],
            mxScore: (int) $row['mx_score'],
            blacklistScore: (int) $row['blacklist_score'],
            checkedAt: (string) $row['checked_at'],
        );
    }
}
