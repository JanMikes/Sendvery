<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Github\GithubApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cron-driven snapshot refresher for `/about/open-source`. Calls the
 * GitHub API once per run, writes the result to `var/github_stats.json`
 * atomically (write to .tmp, then rename — POSIX guarantees rename is
 * atomic within the same filesystem). On any fetch failure the existing
 * file is left intact, so a transient API outage doesn't blank out the
 * stats strip.
 *
 * Schedule via system cron (see CLAUDE.md "Crons"):
 *
 *     0 *\/6 * * *  sentry-cli monitors run sendvery-github-stats -- \
 *         docker compose run --rm worker bin/console sendvery:opensource:refresh-github-stats
 */
#[AsCommand(
    name: 'sendvery:opensource:refresh-github-stats',
    description: 'Fetch the public Sendvery repo stats from GitHub and cache them for /about/open-source',
)]
final class RefreshGithubStatsCommand extends Command
{
    private const REPO = 'janmikes/sendvery';

    public function __construct(
        private readonly GithubApiClient $github,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $stats = $this->github->fetchRepoStats(self::REPO);
        if (false === $stats) {
            $io->error('Failed to fetch repo stats from GitHub. Existing snapshot left intact.');

            return Command::FAILURE;
        }

        $targetPath = $this->projectDir.'/var/github_stats.json';
        $tmpPath = $targetPath.'.tmp';

        $encoded = json_encode($stats, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        if (false === @file_put_contents($tmpPath, $encoded)) {
            $io->error(sprintf('Failed to write temporary snapshot at %s.', $tmpPath));

            return Command::FAILURE;
        }

        if (false === @rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);
            $io->error(sprintf('Failed to atomically rename snapshot into %s.', $targetPath));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'GitHub stats refreshed: %d stars, %d forks, last commit %s.',
            $stats['stars'],
            $stats['forks'],
            $stats['last_commit_at'],
        ));

        return Command::SUCCESS;
    }
}
