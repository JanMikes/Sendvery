<?php

declare(strict_types=1);

namespace App\Results;

/**
 * The `/app` "Needs your attention" section as a whole.
 *
 * `totalCount` counts every domain that needs attention on the team, while
 * `domains` holds only the ones actually rendered — the list is capped so a
 * 200-domain team gets a readable dashboard instead of a second domains page.
 * `hiddenCount` drives the "and N more" link to `/app/domains?status=attention`.
 */
final readonly class DomainAttentionListResult
{
    /**
     * @param list<DomainAttentionResult> $domains worst-first: unverified before attention
     */
    public function __construct(
        public array $domains,
        public int $totalCount,
        public int $hiddenCount,
    ) {
    }
}
