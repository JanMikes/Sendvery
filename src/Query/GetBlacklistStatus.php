<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\BlacklistStatusResult;
use Doctrine\DBAL\Connection;

final readonly class GetBlacklistStatus
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<BlacklistStatusResult>
     */
    public function forDomain(string $domainId): array
    {
        /** @var list<array{id: string, ip_address: string, checked_at: string, results: string, is_listed: bool|string}> $data */
        $data = $this->database->executeQuery(
            'SELECT DISTINCT ON (ip_address) id, ip_address, checked_at, results, is_listed
             FROM blacklist_check_result
             WHERE monitored_domain_id = :domainId
             ORDER BY ip_address, checked_at DESC',
            ['domainId' => $domainId],
        )->fetchAllAssociative();

        return array_map(BlacklistStatusResult::fromDatabaseRow(...), $data);
    }

    /**
     * @return array<BlacklistStatusResult>
     */
    public function historyForIp(string $domainId, string $ipAddress, int $limit = 30): array
    {
        /** @var list<array{id: string, ip_address: string, checked_at: string, results: string, is_listed: bool|string}> $data */
        $data = $this->database->executeQuery(
            'SELECT id, ip_address, checked_at, results, is_listed
             FROM blacklist_check_result
             WHERE monitored_domain_id = :domainId AND ip_address = :ip
             ORDER BY checked_at DESC
             LIMIT :limit',
            [
                'domainId' => $domainId,
                'ip' => $ipAddress,
                'limit' => $limit,
            ],
        )->fetchAllAssociative();

        return array_map(BlacklistStatusResult::fromDatabaseRow(...), $data);
    }
}
