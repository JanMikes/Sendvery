<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ReleaseQuarantinedReportsForTeam;
use App\Query\GetReleasableQuarantinedReports;
use App\Services\Stripe\PlanEnforcement;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Rolls expired `team_usage` and `team_ai_usage` rows forward to the
 * current month, zeroing their counters. `PlanEnforcement` already does
 * this lazily on every read/write — this cron normalizes idle teams so
 * dashboards never show stale "from last month" counts on the first hit
 * after a long gap.
 *
 * Also the moment monthly report capacity comes BACK, so it asks every team
 * holding plan-overage reports to release what now fits. Without this the only
 * release trigger would be a plan upgrade, and a team that never upgrades would
 * keep its own reports parked forever.
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
        private readonly GetReleasableQuarantinedReports $releasableReports,
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rowsReset = $this->enforcement->resetExpiredCounters();

        if (0 === $rowsReset) {
            $io->info('No usage counters to reset.');
        } else {
            $io->success(sprintf('Reset %d usage counter row(s).', $rowsReset));
        }

        // Runs even when nothing was reset: a team's counter may have already
        // been rolled lazily by PlanEnforcement::ensureCurrentPeriod() on a
        // read, and its parked reports would then never be asked for again.
        // The handler re-checks the real headroom per team, so asking is cheap
        // and idempotent.
        $askedToRelease = 0;
        foreach ($this->releasableReports->teamIdsWithOverCapReports() as $teamId) {
            $this->commandBus->dispatch(new ReleaseQuarantinedReportsForTeam(
                teamId: Uuid::fromString($teamId),
            ));
            ++$askedToRelease;
        }

        if ($askedToRelease > 0) {
            $io->info(sprintf('Queued plan-overage report release for %d team(s).', $askedToRelease));
        }

        return Command::SUCCESS;
    }
}
