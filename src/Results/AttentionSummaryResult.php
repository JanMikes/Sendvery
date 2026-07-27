<?php

declare(strict_types=1);

namespace App\Results;

/**
 * Aggregated "things need your attention today" summary rendered between the
 * health-summary banner and the setup checklist on `/app`.
 *
 * {@see \App\Services\AttentionSummaryResolver} is the single producer. The
 * template branches on `totalCount`: zero -> render nothing; otherwise show a
 * compact inline line with `items` as a comma/middot list of deep links.
 *
 * The discrete count fields are kept alongside `items` so callers can read a
 * single signal without having to search `items` for it.
 *
 * `attentionDomainCount` closed a contradiction the `/app` hero used to print:
 * the banner said "3 domains need attention" while the line right underneath
 * said "1 thing needs your attention today", because this summary only knew
 * about UNVERIFIED domains and ignored the Attention bucket entirely. Both
 * domain counts now come from the same {@see \App\Services\DomainHealthClassifier}
 * pass over the same domain set as {@see HealthSummaryResult}, so they cannot
 * disagree.
 */
final readonly class AttentionSummaryResult
{
    /**
     * @param list<AttentionItem> $items severity-ordered: critical alerts → domains needing attention → unverified domains → quarantine
     */
    public function __construct(
        public int $criticalAlertCount,
        public int $attentionDomainCount,
        public int $unverifiedDomainCount,
        public int $quarantineCount,
        public int $totalCount,
        public array $items,
    ) {
    }
}
