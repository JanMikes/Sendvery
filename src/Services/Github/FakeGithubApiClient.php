<?php

declare(strict_types=1);

namespace App\Services\Github;

/**
 * In-memory replacement for `FileGetContentsGithubApiClient` used by
 * tests. Aliased via config/services.php under when@test so the cron
 * test (and any other consumer) never touches the public GitHub API.
 */
final class FakeGithubApiClient implements GithubApiClient
{
    /** @var array{stars: int, forks: int, last_commit_at: string, default_branch: string}|false */
    private array|false $response = false;

    public function fetchRepoStats(string $repo): array|false
    {
        return $this->response;
    }

    /** @param array{stars: int, forks: int, last_commit_at: string, default_branch: string} $response */
    public function setResponse(array $response): void
    {
        $this->response = $response;
    }

    public function simulateFailure(): void
    {
        $this->response = false;
    }
}
