<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\Dns\DnsProtocolStateResult;
use App\Value\DnsCheckType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Many-domain sibling of {@see GetLatestDnsCheckStates}: the newest
 * `dns_check_result` row per (domain, protocol) pair for a whole set of domains
 * in ONE round trip.
 *
 * Exists for the `/app` attention list, which has to reach the same
 * per-protocol verdict the domain page reaches for every domain it lists. The
 * single-domain query in a loop would put one round trip per listed domain on
 * the dashboard hot path — the exact N+1 shape TASK-134 removed from the RUA
 * scenario lookup.
 *
 * `DISTINCT ON (dcr.monitored_domain_id, dcr.type)` extends the single-domain
 * query's "latest row per group" idiom by one grouping column; the matching
 * `ORDER BY` prefix is what makes it pick the newest row per pair.
 */
final readonly class GetLatestDnsCheckStatesForDomains
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The team-scope guard is mandatory for the same reason as the
     * single-domain query: a known domain UUID belonging to another tenant must
     * drop out of the result rather than leak its DNS state.
     *
     * @param list<string> $domainIds monitored-domain UUIDs to look up
     * @param list<string> $teamIds   team UUIDs the caller is allowed to read from
     *
     * @return array<string, array<value-of<DnsCheckType>, DnsProtocolStateResult>> outer key: domain UUID; inner key: {@see DnsCheckType}::value. Domains with no stored check row are absent entirely, which callers read as "no check has run yet".
     */
    public function forDomains(array $domainIds, array $teamIds): array
    {
        if ([] === $domainIds || [] === $teamIds) {
            return [];
        }

        /** @var list<array{domain_id: string, check_type: string, checked_at: string, raw_record: string|null, is_valid: bool|int|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT DISTINCT ON (dcr.monitored_domain_id, dcr.type)
                dcr.monitored_domain_id AS domain_id,
                dcr.type                AS check_type,
                dcr.checked_at          AS checked_at,
                dcr.raw_record          AS raw_record,
                dcr.is_valid            AS is_valid
            FROM dns_check_result dcr
            JOIN monitored_domain md ON md.id = dcr.monitored_domain_id
            WHERE dcr.monitored_domain_id IN (:domainIds)
              AND md.team_id IN (:teamIds)
            ORDER BY dcr.monitored_domain_id, dcr.type, dcr.checked_at DESC',
            [
                'domainIds' => $domainIds,
                'teamIds' => $teamIds,
            ],
            [
                'domainIds' => ArrayParameterType::STRING,
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $byDomain = [];
        foreach ($rows as $row) {
            // Re-projected key by key rather than passed through whole: the
            // result DTO's array shape is sealed, and `domain_id` only exists
            // to group rows here.
            $state = DnsProtocolStateResult::fromDatabaseRow([
                'check_type' => $row['check_type'],
                'checked_at' => $row['checked_at'],
                'raw_record' => $row['raw_record'],
                'is_valid' => $row['is_valid'],
            ]);

            $byDomain[$row['domain_id']][$state->type->value] = $state;
        }

        return $byDomain;
    }
}
