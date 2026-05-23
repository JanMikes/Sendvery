<?php

declare(strict_types=1);

namespace App\Twig;

use App\Value\GithubStats;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Reads `var/github_stats.json` (refreshed by the cron) and exposes a
 * `GithubStats` instance (or null) as the `github_stats` Twig global.
 * Missing file, malformed JSON, or any contract violation collapses to
 * null so the template can omit the stats strip entirely instead of
 * rendering fake placeholders.
 */
final class GithubStatsExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return [
            'github_stats' => $this->loadStats(),
        ];
    }

    private function loadStats(): ?GithubStats
    {
        $path = $this->projectDir.'/var/github_stats.json';
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (false === $raw) {
            return null;
        }

        return GithubStats::fromJson($raw);
    }
}
