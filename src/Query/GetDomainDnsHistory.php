<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DnsCheckHistoryResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetDomainDnsHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<DnsCheckHistoryResult>
     */
    public function forDomain(string $domainId, array $teamIds, int $limit = 100): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<array{id: string, type: string, checked_at: string, raw_record: string|null, is_valid: bool|string, issues: string, details: string, previous_raw_record: string|null, has_changed: bool|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT
                dcr.id,
                dcr.type,
                dcr.checked_at,
                dcr.raw_record,
                dcr.is_valid,
                dcr.issues,
                dcr.details,
                dcr.previous_raw_record,
                dcr.has_changed
            FROM dns_check_result dcr
            JOIN monitored_domain md ON md.id = dcr.monitored_domain_id
            WHERE dcr.monitored_domain_id = :domainId
            AND md.team_id IN (:teamIds)
            ORDER BY dcr.checked_at DESC
            LIMIT :limit',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
                'limit' => $limit,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(DnsCheckHistoryResult::fromDatabaseRow(...), $rows);
    }
}
