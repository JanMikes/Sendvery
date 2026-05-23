<?php

declare(strict_types=1);

namespace App\Services\Github;

/**
 * Production `GithubApiClient` impl. Uses stock `file_get_contents` against
 * the public REST API — `symfony/http-client` is intentionally not pulled
 * in for a single cron hit. GitHub requires a User-Agent on every request;
 * the in-stream context sets one. Unauthenticated rate limit is 60 req/hr
 * per IP, far above our 4-per-day cron cadence.
 */
final readonly class FileGetContentsGithubApiClient implements GithubApiClient
{
    private const USER_AGENT = 'sendvery-cron/1.0';

    public function fetchRepoStats(string $repo): array|false
    {
        if (1 !== preg_match('/\A[a-zA-Z0-9._-]{1,100}\/[a-zA-Z0-9._-]{1,100}\z/', $repo)) {
            return false;
        }

        $repoMeta = $this->httpGetJson(sprintf('https://api.github.com/repos/%s', $repo));
        if (!is_array($repoMeta)) {
            return false;
        }

        $stars = $repoMeta['stargazers_count'] ?? null;
        $forks = $repoMeta['forks_count'] ?? null;
        $defaultBranch = $repoMeta['default_branch'] ?? null;
        if (!is_int($stars) || !is_int($forks) || !is_string($defaultBranch) || '' === $defaultBranch) {
            return false;
        }

        $branchMeta = $this->httpGetJson(sprintf(
            'https://api.github.com/repos/%s/branches/%s',
            $repo,
            rawurlencode($defaultBranch),
        ));
        if (!is_array($branchMeta)) {
            return false;
        }

        $commit = $branchMeta['commit']['commit']['committer']['date'] ?? null;
        if (!is_string($commit) || '' === $commit) {
            return false;
        }

        return [
            'stars' => $stars,
            'forks' => $forks,
            'last_commit_at' => $commit,
            'default_branch' => $defaultBranch,
        ];
    }

    private function httpGetJson(string $url): mixed
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: '.self::USER_AGENT."\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (false === $raw) {
            return null;
        }

        try {
            return json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
