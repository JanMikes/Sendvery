<?php

declare(strict_types=1);

namespace App\Query;

use App\Results\DomainWorkspaceTabCountsResult;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Per-domain attention counters used by the workspace tab strip (TASK-084).
 *
 * Single SQL round-trip — each metric is a scalar subselect inside one
 * `SELECT`, so all six tabs render with one query instead of one per tab.
 * Time windows come from {@see ClockInterface} so tests pin a deterministic
 * "now" without leaning on the database clock (which would force every test
 * to mutate fixture dates against wall-clock and produce flakes — same
 * rationale as {@see GetTeamPassRateAggregates}).
 *
 * Team scoping is intentionally NOT enforced here — the caller controllers
 * load the domain via {@see GetDomainDetail::forDomain()} with team scoping
 * and 404 before this query ever runs, so a domain id arriving here is
 * already proven to belong to the current team.
 */
final readonly class GetDomainWorkspaceTabCounts
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    public function forDomain(string $domainId): DomainWorkspaceTabCountsResult
    {
        $now = $this->clock->now();
        $oneDayAgo = $now->modify('-24 hours');
        $sevenDaysAgo = $now->modify('-7 days');

        $sql = <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM dmarc_report dr
                    WHERE dr.monitored_domain_id = :domainId
                      AND dr.processed_at >= :oneDayAgo) AS reports_24h,
                (SELECT COUNT(*) FROM known_sender ks
                    WHERE ks.monitored_domain_id = :domainId
                      AND ks.is_authorized = FALSE) AS unauthorized_senders,
                (SELECT CASE WHEN dhs.spf_score < 80
                              OR dhs.dkim_score < 80
                              OR dhs.dmarc_score < 80
                              OR dhs.mx_score < 80
                            THEN 1 ELSE 0 END
                    FROM domain_health_snapshot dhs
                    WHERE dhs.monitored_domain_id = :domainId
                    ORDER BY dhs.checked_at DESC
                    LIMIT 1) AS dns_failing,
                (SELECT COUNT(DISTINCT bcr.ip_address) FROM blacklist_check_result bcr
                    WHERE bcr.monitored_domain_id = :domainId
                      AND bcr.is_listed = TRUE
                      AND bcr.id IN (
                          SELECT DISTINCT ON (ip_address) id
                          FROM blacklist_check_result
                          WHERE monitored_domain_id = :domainId
                          ORDER BY ip_address, checked_at DESC
                      )) AS blacklist_listed,
                (SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
                    FROM dns_check_result dcr
                    WHERE dcr.monitored_domain_id = :domainId
                      AND dcr.has_changed = TRUE
                      AND dcr.checked_at >= :sevenDaysAgo
                      AND EXISTS (
                          SELECT 1 FROM dns_check_result earlier
                          WHERE earlier.monitored_domain_id = dcr.monitored_domain_id
                            AND earlier.type = dcr.type
                            AND earlier.checked_at < dcr.checked_at
                      )) AS history_changed_7d
            SQL;

        /** @var array{reports_24h: int|string|null, unauthorized_senders: int|string|null, dns_failing: int|string|bool|null, blacklist_listed: int|string|null, history_changed_7d: int|string|bool|null}|false $row */
        $row = $this->database->executeQuery(
            $sql,
            [
                'domainId' => $domainId,
                'oneDayAgo' => $oneDayAgo->format('Y-m-d H:i:s'),
                'sevenDaysAgo' => $sevenDaysAgo->format('Y-m-d H:i:s'),
            ],
        )->fetchAssociative();

        if (false === $row) {
            // The outer SELECT has no FROM clause, so PostgreSQL always
            // returns exactly one row — this branch is defensive only.
            return new DomainWorkspaceTabCountsResult(
                reports24h: 0,
                unauthorizedSenders: 0,
                dnsFailing: false,
                blacklistListed: 0,
                historyChanged7d: false,
            );
        }

        return DomainWorkspaceTabCountsResult::fromDatabaseRow($row);
    }
}
