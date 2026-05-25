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
 * The discrete count fields are kept alongside `items` so the template can
 * use them for the singular/plural headline ("N thing needs..." / "N things
 * need...") without having to count `items` again.
 */
final readonly class AttentionSummaryResult
{
    /**
     * @param list<AttentionItem> $items severity-ordered: critical alerts → unverified domains → quarantine
     */
    public function __construct(
        public int $criticalAlertCount,
        public int $unverifiedDomainCount,
        public int $quarantineCount,
        public int $totalCount,
        public array $items,
    ) {
    }
}
