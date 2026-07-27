<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\AlertListResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetAlerts
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds    team UUIDs the caller is allowed to read from
     * @param bool|null    $isResolved null keeps resolved alerts in the list — they
     *                                 stay visible as a record that the fix landed;
     *                                 true/false narrows to only/never resolved
     *
     * @return array<AlertListResult>
     */
    public function forTeams(
        array $teamIds,
        ?string $severity = null,
        ?string $type = null,
        ?string $domainId = null,
        ?bool $isRead = null,
        bool $onlySnoozed = false,
        ?bool $isResolved = null,
        int $limit = 50,
    ): array {
        if ([] === $teamIds) {
            return [];
        }

        $sql = 'SELECT
                a.id AS alert_id,
                a.type,
                a.severity,
                a.title,
                a.message,
                a.is_read,
                a.created_at,
                a.snoozed_until,
                a.resolved_at,
                md.id AS domain_id,
                md.domain AS domain_name
            FROM alert a
            LEFT JOIN monitored_domain md ON md.id = a.monitored_domain_id
            WHERE a.team_id IN (:teamIds)';

        $params = ['teamIds' => $teamIds];
        $types = ['teamIds' => ArrayParameterType::STRING];

        if (null !== $severity) {
            $sql .= ' AND a.severity = :severity';
            $params['severity'] = $severity;
        }

        if (null !== $type) {
            $sql .= ' AND a.type = :type';
            $params['type'] = $type;
        }

        if (null !== $domainId) {
            $sql .= ' AND a.monitored_domain_id = :domainId';
            $params['domainId'] = $domainId;
        }

        if (null !== $isRead) {
            $sql .= ' AND a.is_read = :isRead';
            $params['isRead'] = $isRead ? 'true' : 'false';
        }

        if (null !== $isResolved) {
            $sql .= $isResolved
                ? ' AND a.resolved_at IS NOT NULL'
                : ' AND a.resolved_at IS NULL';
        }

        if ($onlySnoozed) {
            // Only currently-snoozed alerts. Expired snoozes are treated as
            // un-snoozed, so they DON'T appear under this filter.
            $sql .= ' AND a.snoozed_until IS NOT NULL AND a.snoozed_until > NOW()';
        } else {
            // Default list hides currently-snoozed alerts. Expired snoozes
            // fall through and are visible again — no manual cleanup needed.
            $sql .= ' AND (a.snoozed_until IS NULL OR a.snoozed_until <= NOW())';
        }

        $sql .= ' ORDER BY a.created_at DESC LIMIT :limit';
        $params['limit'] = $limit;

        /** @var list<array{alert_id: string, type: string, severity: string, title: string, message: string, is_read: bool|string, created_at: string, snoozed_until: string|null, resolved_at: string|null, domain_id: string|null, domain_name: string|null}> $rows */
        $rows = $this->database->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return array_map(AlertListResult::fromDatabaseRow(...), $rows);
    }

    /**
     * The "needs attention" count behind the sidebar badge and the hero
     * summary. Resolved alerts are excluded regardless of their read flag: the
     * problem they describe is gone, so nagging about them would be dishonest.
     *
     * @param list<string> $teamIds        team UUIDs the caller is allowed to read from
     * @param bool         $includeSnoozed counts snoozed-but-unread alerts too. Only
     *                                     "Mark all as read" wants this — it flips the
     *                                     read flag across the whole backlog, so its
     *                                     flash must report that same wider set
     */
    public function countUnreadForTeams(array $teamIds, bool $includeSnoozed = false): int
    {
        if ([] === $teamIds) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) FROM alert
             WHERE team_id IN (:teamIds)
             AND is_read = false
             AND resolved_at IS NULL';

        if (!$includeSnoozed) {
            $sql .= ' AND (snoozed_until IS NULL OR snoozed_until <= NOW())';
        }

        return (int) $this->database->executeQuery(
            $sql,
            ['teamIds' => $teamIds],
            ['teamIds' => ArrayParameterType::STRING],
        )->fetchOne();
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function countUnreadCriticalForTeams(array $teamIds): int
    {
        if ([] === $teamIds) {
            return 0;
        }

        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM alert
             WHERE team_id IN (:teamIds)
             AND is_read = false
             AND severity = :severity
             AND resolved_at IS NULL
             AND (snoozed_until IS NULL OR snoozed_until <= NOW())',
            ['teamIds' => $teamIds, 'severity' => 'critical'],
            ['teamIds' => ArrayParameterType::STRING],
        )->fetchOne();
    }
}
