<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\Dns\DnsProtocolStateResult;
use App\Value\DnsCheckType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The newest `dns_check_result` row per protocol for one domain, in a single
 * round trip. `DISTINCT ON (dcr.type)` + `ORDER BY dcr.type, dcr.checked_at DESC`
 * is the same "latest row per group" idiom
 * {@see \App\Services\Digest\WeeklyDigestGenerator} uses for the digest's
 * broken-DNS section.
 *
 * Callers get the authoritative per-protocol setup state without touching
 * `domain_health_snapshot` (which only the nightly cron writes) or the
 * `*_verified_at` columns (which have no MX equivalent).
 */
final readonly class GetLatestDnsCheckStates
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The team-scope guard is mandatory, exactly like
     * {@see GetDnsHealthOverview::forDomain()}: a known domain UUID belonging to
     * another tenant returns an empty map rather than leaking its DNS state.
     *
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return array<value-of<DnsCheckType>, DnsProtocolStateResult> keyed by {@see DnsCheckType}::value
     */
    public function forDomain(string $domainId, array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<array{check_type: string, checked_at: string, raw_record: string|null, is_valid: bool|int|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT DISTINCT ON (dcr.type)
                dcr.type       AS check_type,
                dcr.checked_at AS checked_at,
                dcr.raw_record AS raw_record,
                dcr.is_valid   AS is_valid
            FROM dns_check_result dcr
            JOIN monitored_domain md ON md.id = dcr.monitored_domain_id
            WHERE dcr.monitored_domain_id = :domainId
              AND md.team_id IN (:teamIds)
            ORDER BY dcr.type, dcr.checked_at DESC',
            [
                'domainId' => $domainId,
                'teamIds' => $teamIds,
            ],
            [
                'teamIds' => ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $states = [];
        foreach ($rows as $row) {
            $state = DnsProtocolStateResult::fromDatabaseRow($row);
            $states[$state->type->value] = $state;
        }

        return $states;
    }
}
