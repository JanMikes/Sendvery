<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\SenderInventoryResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetSenderInventory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<SenderInventoryResult>
     */
    public function forDomain(string $domainId, array $teamIds, ?bool $authorizedFilter = null): array
    {
        if ([] === $teamIds) {
            return [];
        }

        $sql = 'SELECT ks.id, ks.source_ip, ks.hostname, ks.organization, ks.label, ks.is_authorized, ks.first_seen_at, ks.last_seen_at, ks.total_messages, ks.pass_rate
                FROM known_sender ks
                JOIN monitored_domain md ON md.id = ks.monitored_domain_id
                WHERE ks.monitored_domain_id = :domainId
                AND md.team_id IN (:teamIds)';
        $params = [
            'domainId' => $domainId,
            'teamIds' => $teamIds,
        ];
        $types = [
            'teamIds' => ArrayParameterType::STRING,
        ];

        if (null !== $authorizedFilter) {
            $sql .= ' AND ks.is_authorized = :authorized';
            $params['authorized'] = $authorizedFilter ? 'true' : 'false';
        }

        $sql .= ' ORDER BY ks.total_messages DESC';

        /** @var list<array{id: string, source_ip: string, hostname: string|null, organization: string|null, label: string|null, is_authorized: bool|string, first_seen_at: string, last_seen_at: string, total_messages: int|string, pass_rate: float|string}> $data */
        $data = $this->database->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return array_map(SenderInventoryResult::fromDatabaseRow(...), $data);
    }
}
