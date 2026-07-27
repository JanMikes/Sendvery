<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\BlacklistCheckCandidate;
use Doctrine\DBAL\Connection;

/**
 * Which IPs are due a DNSBL lookup, for domains whose team has paid for it.
 *
 * THE THREE CONSTRAINTS, ALL FOR THE SAME REASON. Public DNSBLs are a shared
 * resource that Sendvery does not own: Spamhaus and Barracuda in particular
 * rate-limit by resolver and will null-route one that gets noisy. A single
 * greedy sweep would take blacklist monitoring down for every customer at once,
 * so the query is bounded three ways before it returns a row:
 *
 *  1. PAID TEAMS ONLY — `blacklist_monitoring` is gated in PlanLimits, and
 *     spending a rate-limited resource on capacity nobody bought is how the
 *     budget disappears.
 *  2. GLOBAL PER-IP FRESHNESS, not per-domain. Shared infrastructure is the
 *     norm — Google, Mailchimp and every ESP send for thousands of domains from
 *     the same addresses — so deduping per domain would re-ask the identical
 *     question once per customer. The freshness check deliberately ignores
 *     which domain last asked.
 *  3. NEWEST SENDERS FIRST, CAPPED PER DOMAIN. One domain with a large
 *     inventory must not be able to exhaust the sweep. Recency is the right
 *     ordering because a listing matters for addresses currently sending.
 */
final readonly class GetBlacklistCheckCandidates
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $paidPlans    plan identifiers entitled to the feature
     * @param int          $cacheHours   how long a verdict stays current
     * @param int          $perDomainCap most IPs to check for any one domain
     *
     * @return list<BlacklistCheckCandidate>
     */
    public function due(array $paidPlans, int $cacheHours, int $perDomainCap): array
    {
        if ([] === $paidPlans) {
            return [];
        }

        /** @var list<array{domain_id: string, source_ip: string}> $rows */
        $rows = $this->database->executeQuery(
            'WITH ranked AS (
                SELECT
                    ks.monitored_domain_id,
                    ks.source_ip,
                    ROW_NUMBER() OVER (
                        PARTITION BY ks.monitored_domain_id
                        ORDER BY ks.last_seen_at DESC
                    ) AS rank_in_domain
                FROM known_sender ks
                JOIN monitored_domain md ON md.id = ks.monitored_domain_id
                JOIN team t ON t.id = md.team_id
                WHERE t.plan IN (:plans)
            )
            SELECT
                ranked.monitored_domain_id::text AS domain_id,
                ranked.source_ip
            FROM ranked
            WHERE ranked.rank_in_domain <= :cap
              AND NOT EXISTS (
                  SELECT 1
                  FROM blacklist_check_result bcr
                  WHERE bcr.ip_address = ranked.source_ip
                    AND bcr.checked_at > NOW() - make_interval(hours => :cacheHours)
              )
            ORDER BY ranked.monitored_domain_id, ranked.source_ip',
            [
                'plans' => $paidPlans,
                'cap' => $perDomainCap,
                'cacheHours' => $cacheHours,
            ],
            [
                'plans' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        return array_map(BlacklistCheckCandidate::fromDatabaseRow(...), $rows);
    }
}
