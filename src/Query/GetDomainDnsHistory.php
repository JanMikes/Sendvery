<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DnsCheckHistoryResult;
use App\Value\DnsCheckType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

final readonly class GetDomainDnsHistory
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<DnsCheckHistoryResult>
     */
    public function forDomain(
        string $domainId,
        array $teamIds,
        ?DnsCheckType $type = null,
        int $rangeDays = 30,
        bool $changesOnly = false,
        int $limit = 100,
    ): array {
        if ([] === $teamIds) {
            return [];
        }

        // `is_initial_check` is true when no earlier `dns_check_result` row
        // exists for the same `(monitored_domain_id, type)` pair. A baseline
        // is not a change; the template uses this to render an INITIAL CHECK
        // badge instead of CHANGED on the first observation per protocol.
        $sql = 'SELECT
                dcr.id,
                dcr.type,
                dcr.checked_at,
                dcr.raw_record,
                dcr.is_valid,
                dcr.issues,
                dcr.details,
                dcr.previous_raw_record,
                dcr.has_changed,
                NOT EXISTS (
                    SELECT 1
                    FROM dns_check_result earlier
                    WHERE earlier.monitored_domain_id = dcr.monitored_domain_id
                    AND earlier.type = dcr.type
                    AND earlier.checked_at < dcr.checked_at
                ) AS is_initial_check
            FROM dns_check_result dcr
            JOIN monitored_domain md ON md.id = dcr.monitored_domain_id
            WHERE dcr.monitored_domain_id = :domainId
            AND md.team_id IN (:teamIds)';

        $params = [
            'domainId' => $domainId,
            'teamIds' => $teamIds,
            'limit' => $limit,
        ];

        $types = [
            'teamIds' => ArrayParameterType::STRING,
        ];

        if (null !== $type) {
            $sql .= ' AND dcr.type = :type';
            $params['type'] = $type->value;
        }

        if ($rangeDays > 0) {
            $sql .= ' AND dcr.checked_at >= :since';
            $params['since'] = $this->clock->now()
                ->modify(sprintf('-%d days', $rangeDays))
                ->format('Y-m-d H:i:s');
        }

        if ($changesOnly) {
            // Baselines are not changes — exclude them so the "Show only
            // changes" toggle doesn't surface freshly-added domains as if
            // something just flipped.
            $sql .= ' AND dcr.has_changed = TRUE
                AND EXISTS (
                    SELECT 1
                    FROM dns_check_result earlier
                    WHERE earlier.monitored_domain_id = dcr.monitored_domain_id
                    AND earlier.type = dcr.type
                    AND earlier.checked_at < dcr.checked_at
                )';
        }

        $sql .= ' ORDER BY dcr.checked_at DESC LIMIT :limit';

        /** @var list<array{id: string, type: string, checked_at: string, raw_record: string|null, is_valid: bool|string, issues: string, details: string, previous_raw_record: string|null, has_changed: bool|string, is_initial_check: bool|string}> $rows */
        $rows = $this->database->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return array_map(DnsCheckHistoryResult::fromDatabaseRow(...), $rows);
    }

    /**
     * @param list<string> $teamIds
     */
    public function countChanges(
        string $domainId,
        array $teamIds,
        ?DnsCheckType $type = null,
        int $rangeDays = 30,
    ): int {
        if ([] === $teamIds) {
            return 0;
        }

        // Count only *real* changes — exclude the per-protocol baseline so
        // a freshly-added domain doesn't report "4 changes" on day one.
        $sql = 'SELECT COUNT(*)
            FROM dns_check_result dcr
            JOIN monitored_domain md ON md.id = dcr.monitored_domain_id
            WHERE dcr.monitored_domain_id = :domainId
            AND md.team_id IN (:teamIds)
            AND dcr.has_changed = TRUE
            AND EXISTS (
                SELECT 1
                FROM dns_check_result earlier
                WHERE earlier.monitored_domain_id = dcr.monitored_domain_id
                AND earlier.type = dcr.type
                AND earlier.checked_at < dcr.checked_at
            )';

        $params = [
            'domainId' => $domainId,
            'teamIds' => $teamIds,
        ];

        $types = [
            'teamIds' => ArrayParameterType::STRING,
        ];

        if (null !== $type) {
            $sql .= ' AND dcr.type = :type';
            $params['type'] = $type->value;
        }

        if ($rangeDays > 0) {
            $sql .= ' AND dcr.checked_at >= :since';
            $params['since'] = $this->clock->now()
                ->modify(sprintf('-%d days', $rangeDays))
                ->format('Y-m-d H:i:s');
        }

        return (int) $this->database->executeQuery($sql, $params, $types)->fetchOne();
    }

    /**
     * @param list<string> $teamIds
     */
    public function hasAnyHistory(string $domainId, array $teamIds): bool
    {
        if ([] === $teamIds) {
            return false;
        }

        $result = $this->database->executeQuery(
            'SELECT EXISTS(
                SELECT 1
                FROM dns_check_result dcr
                JOIN monitored_domain md ON md.id = dcr.monitored_domain_id
                WHERE dcr.monitored_domain_id = :domainId
                AND md.team_id IN (:teamIds)
            )',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchOne();

        return (bool) $result;
    }
}
