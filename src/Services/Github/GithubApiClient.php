<?php

declare(strict_types=1);

namespace App\Services\Github;

/**
 * Minimal contract over the GitHub REST API surface we care about:
 * a repo's stars / forks / last commit / default branch. Kept narrow so
 * the cron command stays a one-liner and tests can bind a deterministic
 * fake. The production implementation hits `api.github.com` via
 * `file_get_contents` — no new SDK or HTTP client dependency.
 */
interface GithubApiClient
{
    /**
     * @return array{stars: int, forks: int, last_commit_at: string, default_branch: string}|false
     *
     * Returns `false` on any transport / parse / contract failure so the cron
     * can keep the previous JSON snapshot intact instead of overwriting it
     * with garbage. Successful responses always include the four fields the
     * `GithubStats::fromJson` factory expects.
     */
    public function fetchRepoStats(string $repo): array|false;
}
