<?php

declare(strict_types=1);

namespace App\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Returns the timestamp of the oldest monitored domain across one or more
 * teams. Used to anchor TASK-091's 7-day grace window before the dashboard
 * falls back from "Publish a DMARC RUA record" to "Connect a mailbox" —
 * once the team's earliest domain has existed long enough without central
 * inbox reports, DNS-based ingestion is treated as broken/blocked.
 */
final readonly class GetEarliestDomainAddedAt
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function forTeams(array $teamIds): ?\DateTimeImmutable
    {
        if ([] === $teamIds) {
            return null;
        }

        /** @var string|false $value */
        $value = $this->database->executeQuery(
            'SELECT MIN(created_at) FROM monitored_domain WHERE team_id IN (:teamIds)',
            ['teamIds' => $teamIds],
            ['teamIds' => ArrayParameterType::STRING],
        )->fetchOne();

        if (false === $value || null === $value) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }
}
