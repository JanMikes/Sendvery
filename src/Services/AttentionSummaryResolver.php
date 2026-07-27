<?php

declare(strict_types=1);

namespace App\Services;

use App\Query\GetAlerts;
use App\Query\GetQuarantineList;
use App\Results\AttentionItem;
use App\Results\AttentionSummaryResult;
use App\Results\DomainOverviewResult;
use App\Value\DomainHealthFilter;

/**
 * Aggregates every "something is wrong" signal on `/app` into one set of
 * deep-linked chips: critical alerts, domains needing attention, unverified
 * domains, quarantine pile-up.
 *
 * Order is fixed by severity (highest first): critical alerts → domains needing
 * attention → unverified domains → quarantine. Each {@see AttentionItem} only
 * materialises when its count is >= 1, so the template can iterate without
 * re-checking thresholds.
 *
 * DOMAIN COUNTS ARE CLASSIFIED, NOT QUERIED. They come from
 * {@see DomainHealthClassifier} over the domain set the caller already fetched —
 * the same pass {@see HealthSummaryResolver} makes. Before that, this resolver
 * ran its own `countUnverifiedForTeams()` query and knew nothing about the
 * Attention bucket, which is why the hero could print "3 domains need attention"
 * directly above "1 thing needs your attention today". One classification pass
 * over one domain list makes that class of disagreement unrepresentable.
 *
 * Auto-resolved alerts are deliberately absent: {@see GetAlerts} filters them
 * out of every count, so a DNS record that was fixed silently drops off this
 * summary instead of asking the user to acknowledge a problem that no longer
 * exists.
 */
final readonly class AttentionSummaryResolver
{
    public function __construct(
        private GetAlerts $getAlerts,
        private GetQuarantineList $getQuarantineList,
        private DomainHealthClassifier $domainHealthClassifier,
    ) {
    }

    /**
     * @param array<DomainOverviewResult> $domains every monitored domain in scope, as already fetched by the caller
     */
    public function resolveForTeam(string $teamId, array $domains): AttentionSummaryResult
    {
        $criticalAlertCount = $this->getAlerts->countUnreadCriticalForTeams([$teamId]);
        $quarantineCount = $this->getQuarantineList->countForTeam($teamId);

        $attentionDomainCount = 0;
        $unverifiedDomainCount = 0;
        foreach ($domains as $domain) {
            match ($this->domainHealthClassifier->classifyOverview($domain)) {
                DomainHealthFilter::Attention => ++$attentionDomainCount,
                DomainHealthFilter::Unverified => ++$unverifiedDomainCount,
                DomainHealthFilter::Healthy => null,
            };
        }

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

        if ($attentionDomainCount > 0) {
            $items[] = new AttentionItem(
                // Legend phrasing, not a sentence: the health headline directly
                // above this row already says "N domains need attention", and a
                // chip echoing it word for word is the duplicated-summary noise
                // this row exists to replace.
                label: 1 === $attentionDomainCount
                    ? '1 needs attention'
                    : sprintf('%d need attention', $attentionDomainCount),
                route: 'dashboard_domains',
                routeParams: ['status' => 'attention'],
                colorClass: 'text-warning',
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
                colorClass: 'text-error',
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
            attentionDomainCount: $attentionDomainCount,
            unverifiedDomainCount: $unverifiedDomainCount,
            quarantineCount: $quarantineCount,
            totalCount: $criticalAlertCount + $attentionDomainCount + $unverifiedDomainCount + $quarantineCount,
            items: $items,
        );
    }
}
