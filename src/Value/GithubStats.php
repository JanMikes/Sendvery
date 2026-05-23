<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Snapshot of repository stats served on /about/open-source. Refreshed
 * by `sendvery:opensource:refresh-github-stats` and persisted to
 * `var/github_stats.json`. The Twig extension reads the file once per
 * request and exposes either a `GithubStats` instance or `null` — null
 * suppresses the stats strip entirely so we never render fake placeholders.
 */
final readonly class GithubStats
{
    public function __construct(
        public int $stars,
        public int $forks,
        public \DateTimeImmutable $lastCommitAt,
        public string $defaultBranch,
    ) {
    }

    public static function fromJson(string $json): ?self
    {
        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $stars = $decoded['stars'] ?? null;
        $forks = $decoded['forks'] ?? null;
        $lastCommitAt = $decoded['last_commit_at'] ?? null;
        $defaultBranch = $decoded['default_branch'] ?? null;

        if (!is_int($stars) || !is_int($forks) || !is_string($lastCommitAt) || !is_string($defaultBranch)) {
            return null;
        }

        if ('' === $defaultBranch) {
            return null;
        }

        try {
            $parsedDate = new \DateTimeImmutable($lastCommitAt);
        } catch (\DateMalformedStringException) {
            return null;
        }

        return new self(
            stars: $stars,
            forks: $forks,
            lastCommitAt: $parsedDate,
            defaultBranch: $defaultBranch,
        );
    }
}
