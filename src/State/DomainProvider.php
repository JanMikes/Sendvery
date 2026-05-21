<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\DomainResource;
use App\Services\DashboardContext;
use Doctrine\DBAL\Connection;

/** @implements ProviderInterface<DomainResource> */
final readonly class DomainProvider implements ProviderInterface
{
    public function __construct(
        private Connection $database,
        private DashboardContext $dashboardContext,
    ) {
    }

    /** @return DomainResource|array<DomainResource>|null */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DomainResource|array|null
    {
        $teamId = $this->dashboardContext->getTeamId()->toString();

        if (isset($uriVariables['id'])) {
            return $this->getOne((string) $uriVariables['id'], $teamId);
        }

        return $this->getAll($teamId);
    }

    private const string SELECT_COLUMNS = 'id, domain, dmarc_policy, spf_verified_at, dkim_verified_at, dmarc_verified_at, first_report_at, created_at';

    private function getOne(string $id, string $teamId): ?DomainResource
    {
        $row = $this->database->executeQuery(
            'SELECT '.self::SELECT_COLUMNS.' FROM monitored_domain WHERE id = :id AND team_id = :teamId',
            ['id' => $id, 'teamId' => $teamId],
        )->fetchAssociative();

        if (false === $row) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return array<DomainResource>
     */
    private function getAll(string $teamId): array
    {
        $rows = $this->database->executeQuery(
            'SELECT '.self::SELECT_COLUMNS.' FROM monitored_domain WHERE team_id = :teamId ORDER BY created_at DESC',
            ['teamId' => $teamId],
        )->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): DomainResource
    {
        $dmarcVerifiedAt = $this->nullableString($row['dmarc_verified_at']);

        return new DomainResource(
            id: (string) $row['id'],
            domain: (string) $row['domain'],
            dmarcPolicy: null !== $row['dmarc_policy'] ? (string) $row['dmarc_policy'] : null,
            isVerified: null !== $dmarcVerifiedAt,
            spfVerifiedAt: $this->nullableString($row['spf_verified_at']),
            dkimVerifiedAt: $this->nullableString($row['dkim_verified_at']),
            dmarcVerifiedAt: $dmarcVerifiedAt,
            firstReportAt: $this->nullableString($row['first_report_at']),
            createdAt: (string) $row['created_at'],
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
