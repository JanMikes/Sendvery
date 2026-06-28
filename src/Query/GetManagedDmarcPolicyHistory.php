<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\ManagedDmarcPolicyChangeResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

/**
 * The managed-DMARC policy-change audit history for a domain, newest first,
 * team-scoped for the dashboard card's "Recent changes" panel.
 */
final readonly class GetManagedDmarcPolicyHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<UuidInterface> $teamIds
     *
     * @return list<ManagedDmarcPolicyChangeResult>
     */
    public function forDomain(UuidInterface $domainId, array $teamIds, int $limit = 10): array
    {
        if ([] === $teamIds) {
            return [];
        }

        // $limit is a typed int — safe to interpolate (PostgreSQL/DBAL won't bind
        // a parameter in the LIMIT clause).
        /** @var list<array{id: string, source: string, from_policy: ?string, to_policy: string, actor_user_id: ?string, created_at: string}> $rows */
        $rows = $this->database->executeQuery(
            sprintf(
                'SELECT id, source, from_policy, to_policy, actor_user_id, created_at
                 FROM managed_dmarc_policy_change
                 WHERE monitored_domain_id = :domainId
                   AND team_id IN (:teamIds)
                 ORDER BY created_at DESC
                 LIMIT %d',
                $limit,
            ),
            [
                'domainId' => $domainId->toString(),
                'teamIds' => array_map(static fn (UuidInterface $id): string => $id->toString(), $teamIds),
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(ManagedDmarcPolicyChangeResult::fromDatabaseRow(...), $rows);
    }
}
