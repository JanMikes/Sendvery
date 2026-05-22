<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\BlacklistStatusResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetBlacklistStatus
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<BlacklistStatusResult>
     */
    public function forDomain(string $domainId, array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<array{id: string, ip_address: string, checked_at: string, results: string, is_listed: bool|string}> $data */
        $data = $this->database->executeQuery(
            'SELECT DISTINCT ON (bcr.ip_address) bcr.id, bcr.ip_address, bcr.checked_at, bcr.results, bcr.is_listed
             FROM blacklist_check_result bcr
             JOIN monitored_domain md ON md.id = bcr.monitored_domain_id
             WHERE bcr.monitored_domain_id = :domainId
             AND md.team_id IN (:teamIds)
             ORDER BY bcr.ip_address, bcr.checked_at DESC',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(BlacklistStatusResult::fromDatabaseRow(...), $data);
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<BlacklistStatusResult>
     */
    public function historyForIp(string $domainId, array $teamIds, string $ipAddress, int $limit = 30): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<array{id: string, ip_address: string, checked_at: string, results: string, is_listed: bool|string}> $data */
        $data = $this->database->executeQuery(
            'SELECT bcr.id, bcr.ip_address, bcr.checked_at, bcr.results, bcr.is_listed
             FROM blacklist_check_result bcr
             JOIN monitored_domain md ON md.id = bcr.monitored_domain_id
             WHERE bcr.monitored_domain_id = :domainId
             AND md.team_id IN (:teamIds)
             AND bcr.ip_address = :ip
             ORDER BY bcr.checked_at DESC
             LIMIT :limit',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
                'ip' => $ipAddress,
                'limit' => $limit,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(BlacklistStatusResult::fromDatabaseRow(...), $data);
    }
}
