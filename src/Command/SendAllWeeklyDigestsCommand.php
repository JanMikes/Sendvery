<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SendWeeklyDigest;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'sendvery:digest:send-all',
    description: 'Send weekly digest emails to all active teams',
)]
final class SendAllWeeklyDigestsCommand extends Command
{
    public function __construct(
        private readonly Connection $database,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $teamIds = $this->database->executeQuery(
            'SELECT DISTINCT t.id
             FROM team t
             JOIN team_membership tm ON tm.team_id = t.id
             JOIN "user" u ON u.id = tm.user_id
             WHERE u.onboarding_completed_at IS NOT NULL
             ORDER BY t.id',
        )->fetchFirstColumn();

        $io->info(sprintf('Dispatching weekly digest for %d teams.', count($teamIds)));

        foreach ($teamIds as $teamId) {
            $this->messageBus->dispatch(new SendWeeklyDigest(
                teamId: Uuid::fromString($teamId),
            ));
        }

        $io->success('All weekly digests dispatched.');

        return Command::SUCCESS;
    }
}
