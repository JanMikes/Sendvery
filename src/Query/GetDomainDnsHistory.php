<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DnsCheckHistoryResult;
use Doctrine\DBAL\Connection;

final readonly class GetDomainDnsHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<DnsCheckHistoryResult>
     */
    public function forDomain(string $domainId, int $limit = 100): array
    {
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
            WHERE dcr.monitored_domain_id = :domainId
            ORDER BY dcr.checked_at DESC
            LIMIT :limit',
            [
                'domainId' => $domainId,
                'limit' => $limit,
            ],
        )->fetchAllAssociative();

        return array_map(DnsCheckHistoryResult::fromDatabaseRow(...), $rows);
    }
}
