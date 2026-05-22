<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\DmarcReportRepository;
use App\Services\Stripe\PlanLimits;
use App\Value\SubscriptionPlan;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Per-team DMARC report purge. Reads each team's plan from `team.plan`,
 * looks up `PlanLimits::getRetentionDays()`, and hard-deletes parsed
 * `DmarcReport` rows older than that window for the team's monitored
 * domains.
 *
 * This is the one place Sendvery deletes user data outside of an explicit
 * user action (see `never-delete-user-data` memory) — retention is a
 * contractual delete, by design. `null` retention = unlimited (Business,
 * Unlimited) means the team is skipped here.
 *
 * Envelope-level cleanup is owned by `sendvery:reports:purge` (a separate
 * concern, different TTL semantics, different command name).
 */
#[AsCommand(
    name: 'sendvery:dmarc:purge',
    description: 'Purge parsed DMARC reports older than each team\'s plan retention window',
)]
final class PurgeOldDmarcReportsCommand extends Command
{
    public function __construct(
        private readonly Connection $database,
        private readonly EntityManagerInterface $entityManager,
        private readonly DmarcReportRepository $reportRepository,
        private readonly PlanLimits $planLimits,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = $this->database
            ->executeQuery('SELECT id, plan FROM team')
            ->fetchAllAssociative();

        $totalDeleted = 0;
        $teamsTouched = 0;
        $now = $this->clock->now();

        foreach ($rows as $row) {
            $teamIdString = (string) $row['id'];
            $planValue = (string) $row['plan'];

            $plan = SubscriptionPlan::tryFrom($planValue);
            if (null === $plan) {
                $io->warning(sprintf('Team %s has unknown plan "%s" — skipping.', $teamIdString, $planValue));

                continue;
            }

            $retentionDays = $this->planLimits->getRetentionDays($plan);
            if (null === $retentionDays) {
                // Unlimited retention — Business and Unlimited tiers.
                continue;
            }

            $cutoff = $now->modify('-'.$retentionDays.' days');

            $deleted = $this->reportRepository->deleteOlderThanForTeam(
                Uuid::fromString($teamIdString),
                $cutoff,
            );

            if ($deleted > 0) {
                $totalDeleted += $deleted;
                ++$teamsTouched;
            }
        }

        // DQL DELETE bypasses the EntityManager, so no flush is needed;
        // we clear the UoW so any cached entities don't drift stale.
        $this->entityManager->clear();

        if (0 === $totalDeleted) {
            $io->info('No DMARC reports to purge.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Purged %d DMARC report(s) across %d team(s).',
            $totalDeleted,
            $teamsTouched,
        ));

        return Command::SUCCESS;
    }
}
