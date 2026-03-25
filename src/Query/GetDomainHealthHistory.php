<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainHealthSnapshotResult;
use Doctrine\DBAL\Connection;

final readonly class GetDomainHealthHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<DomainHealthSnapshotResult>
     */
    public function forDomain(string $domainId, int $limit = 90): array
    {
        $data = $this->database->executeQuery(
            'SELECT id, grade, score, spf_score, dkim_score, dmarc_score, mx_score, blacklist_score, checked_at, recommendations, share_hash
             FROM domain_health_snapshot
             WHERE monitored_domain_id = :domainId
             ORDER BY checked_at DESC
             LIMIT :limit',
            [
                'domainId' => $domainId,
                'limit' => $limit,
            ],
        )->fetchAllAssociative();

        return array_map(DomainHealthSnapshotResult::fromDatabaseRow(...), $data);
    }

    public function latestForDomain(string $domainId): ?DomainHealthSnapshotResult
    {
        $data = $this->database->executeQuery(
            'SELECT id, grade, score, spf_score, dkim_score, dmarc_score, mx_score, blacklist_score, checked_at, recommendations, share_hash
             FROM domain_health_snapshot
             WHERE monitored_domain_id = :domainId
             ORDER BY checked_at DESC
             LIMIT 1',
            ['domainId' => $domainId],
        )->fetchAssociative();

        if (false === $data) {
            return null;
        }

        return DomainHealthSnapshotResult::fromDatabaseRow($data);
    }

    public function findByShareHash(string $shareHash): ?DomainHealthSnapshotResult
    {
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
