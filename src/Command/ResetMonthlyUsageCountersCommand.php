<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Stripe\PlanEnforcement;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rolls expired `team_usage` and `team_ai_usage` rows forward to the
 * current month, zeroing their counters. `PlanEnforcement` already does
 * this lazily on every read/write — this cron normalizes idle teams so
 * dashboards never show stale "from last month" counts on the first hit
 * after a long gap.
 *
 * Scheduled by system cron (see CLAUDE.md "Crons"). Add to
 * `~/www/spare.srv/deployment/crontab` under `## Sendvery`:
 *
 *     0 0 * * *  ... bin/console sendvery:usage:reset
 *
 * Wrap in `sentry-cli monitors run` so missed runs page.
 */
#[AsCommand(
    name: 'sendvery:usage:reset',
    description: 'Reset monthly plan-usage counters for any teams whose billing period has expired',
)]
final class ResetMonthlyUsageCountersCommand extends Command
{
    public function __construct(
        private readonly PlanEnforcement $enforcement,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rowsReset = $this->enforcement->resetExpiredCounters();

        if (0 === $rowsReset) {
            $io->info('No usage counters to reset.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Reset %d usage counter row(s).', $rowsReset));

        return Command::SUCCESS;
    }
}
