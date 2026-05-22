<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainHealthSnapshotResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class GetDomainHealthHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<DomainHealthSnapshotResult>
     */
    public function forDomain(string $domainId, array $teamIds, int $limit = 90): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<array{id: string, grade: string, score: int|string, spf_score: int|string, dkim_score: int|string, dmarc_score: int|string, mx_score: int|string, blacklist_score: int|string, checked_at: string, recommendations: string, share_hash: string|null}> $data */
        $data = $this->database->executeQuery(
            'SELECT dhs.id, dhs.grade, dhs.score, dhs.spf_score, dhs.dkim_score, dhs.dmarc_score, dhs.mx_score, dhs.blacklist_score, dhs.checked_at, dhs.recommendations, dhs.share_hash
             FROM domain_health_snapshot dhs
             JOIN monitored_domain md ON md.id = dhs.monitored_domain_id
             WHERE dhs.monitored_domain_id = :domainId
             AND md.team_id IN (:teamIds)
             ORDER BY dhs.checked_at DESC
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

        return array_map(DomainHealthSnapshotResult::fromDatabaseRow(...), $data);
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     */
    public function latestForDomain(string $domainId, array $teamIds): ?DomainHealthSnapshotResult
    {
        if ([] === $teamIds) {
            return null;
        }

        /** @var array{id: string, grade: string, score: int|string, spf_score: int|string, dkim_score: int|string, dmarc_score: int|string, mx_score: int|string, blacklist_score: int|string, checked_at: string, recommendations: string, share_hash: string|null}|false $data */
        $data = $this->database->executeQuery(
            'SELECT dhs.id, dhs.grade, dhs.score, dhs.spf_score, dhs.dkim_score, dhs.dmarc_score, dhs.mx_score, dhs.blacklist_score, dhs.checked_at, dhs.recommendations, dhs.share_hash
             FROM domain_health_snapshot dhs
             JOIN monitored_domain md ON md.id = dhs.monitored_domain_id
             WHERE dhs.monitored_domain_id = :domainId
             AND md.team_id IN (:teamIds)
             ORDER BY dhs.checked_at DESC
             LIMIT 1',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if (false === $data) {
            return null;
        }

        return DomainHealthSnapshotResult::fromDatabaseRow($data);
    }

    /**
     * Public share link — the share_hash is the authorisation token, so no
     * team scoping. Anyone with the hash can see the snapshot.
     */
    public function findByShareHash(string $shareHash): ?DomainHealthSnapshotResult
    {
        /** @var array{id: string, grade: string, score: int|string, spf_score: int|string, dkim_score: int|string, dmarc_score: int|string, mx_score: int|string, blacklist_score: int|string, checked_at: string, recommendations: string, share_hash: string|null}|false $data */
        $data = $this->database->executeQuery(
            'SELECT dhs.id, dhs.grade, dhs.score, dhs.spf_score, dhs.dkim_score, dhs.dmarc_score, dhs.mx_score, dhs.blacklist_score, dhs.checked_at, dhs.recommendations, dhs.share_hash
             FROM domain_health_snapshot dhs
             WHERE dhs.share_hash = :hash
             ORDER BY dhs.checked_at DESC
             LIMIT 1',
            ['hash' => $shareHash],
        )->fetchAssociative();

        if (false === $data) {
            return null;
        }

        return DomainHealthSnapshotResult::fromDatabaseRow($data);
    }

    /**
     * @return array{domain_name: string}|null
     */
    public function getDomainNameByShareHash(string $shareHash): ?array
    {
        $data = $this->database->executeQuery(
            'SELECT md.domain AS domain_name
             FROM domain_health_snapshot dhs
             JOIN monitored_domain md ON md.id = dhs.monitored_domain_id
             WHERE dhs.share_hash = :hash
             LIMIT 1',
            ['hash' => $shareHash],
        )->fetchAssociative();

        if (false === $data) {
            return null;
        }

        return ['domain_name' => $data['domain_name']];
    }
}
