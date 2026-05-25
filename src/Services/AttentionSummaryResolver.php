<?php

declare(strict_types=1);

namespace App\Services;

use App\Query\GetAlerts;
use App\Query\GetDomainOverview;
use App\Query\GetQuarantineList;
use App\Results\AttentionItem;
use App\Results\AttentionSummaryResult;

/**
 * Aggregates the three sidebar-badge signals (critical alerts, unverified
 * domains, quarantine pile-up) into a single hero summary line for `/app`.
 *
 * Order is fixed by severity (highest first): critical alerts → unverified
 * domains → quarantine. Each {@see AttentionItem} only materialises when its
 * count is >= 1, so the template can iterate without re-checking thresholds.
 *
 * Pure aggregator: no caching, no derived totals beyond the obvious sum. The
 * counts re-issue four small COUNT queries via the underlying query classes —
 * the same ones {@see \App\Twig\NavCountsExtension} uses for the sidebar
 * badges, so subsequent renders of the same page share the query-result cache
 * at the DBAL layer.
 */
final readonly class AttentionSummaryResolver
{
    public function __construct(
        private GetAlerts $getAlerts,
        private GetQuarantineList $getQuarantineList,
        private GetDomainOverview $getDomainOverview,
    ) {
    }

    public function resolveForTeam(string $teamId): AttentionSummaryResult
    {
        $criticalAlertCount = $this->getAlerts->countUnreadCriticalForTeams([$teamId]);
        $unverifiedDomainCount = $this->getDomainOverview->countUnverifiedForTeams([$teamId]);
        $quarantineCount = $this->getQuarantineList->countForTeam($teamId);

        $items = [];

        if ($criticalAlertCount > 0) {
            $items[] = new AttentionItem(
                label: sprintf(
                    '%d critical %s',
                    $criticalAlertCount,
                    1 === $criticalAlertCount ? 'alert' : 'alerts',
                ),
                route: 'dashboard_alerts',
                routeParams: ['severity' => 'critical', 'isRead' => '0'],
                colorClass: 'text-error',
            );
        }

        if ($unverifiedDomainCount > 0) {
            $items[] = new AttentionItem(
                label: sprintf(
                    '%d unverified %s',
                    $unverifiedDomainCount,
                    1 === $unverifiedDomainCount ? 'domain' : 'domains',
                ),
                route: 'dashboard_domains',
                routeParams: ['status' => 'unverified'],
                colorClass: 'text-warning',
            );
        }

        if ($quarantineCount > 0) {
            $items[] = new AttentionItem(
                label: sprintf(
                    '%d %s in quarantine',
                    $quarantineCount,
                    1 === $quarantineCount ? 'report' : 'reports',
                ),
                route: 'dashboard_quarantine',
                routeParams: [],
                colorClass: 'text-warning',
            );
        }

        return new AttentionSummaryResult(
            criticalAlertCount: $criticalAlertCount,
            unverifiedDomainCount: $unverifiedDomainCount,
            quarantineCount: $quarantineCount,
            totalCount: $criticalAlertCount + $unverifiedDomainCount + $quarantineCount,
            items: $items,
        );
    }
}
