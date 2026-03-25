<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\SenderInventoryResult;
use Doctrine\DBAL\Connection;

final readonly class GetSenderInventory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<SenderInventoryResult>
     */
    public function forDomain(string $domainId, ?bool $authorizedFilter = null): array
    {
        $sql = 'SELECT id, source_ip, hostname, organization, label, is_authorized, first_seen_at, last_seen_at, total_messages, pass_rate
                FROM known_sender
                WHERE monitored_domain_id = :domainId';
        $params = ['domainId' => $domainId];

        if (null !== $authorizedFilter) {
            $sql .= ' AND is_authorized = :authorized';
            $params['authorized'] = $authorizedFilter ? 'true' : 'false';
        }

        $sql .= ' ORDER BY total_messages DESC';

        $data = $this->database->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(SenderInventoryResult::fromDatabaseRow(...), $data);
    }
}
