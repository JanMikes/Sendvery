<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\PollReportsInbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pulls a fresh batch from the central reports@sendvery.com inbox.
 *
 * Wired to system cron every 5 minutes via the deployment crontab.
 * Default mode dispatches an async PollReportsInbox message and returns;
 * --sync runs the ingestion inline for ops debugging.
 */
#[AsCommand(
    name: 'sendvery:reports:poll-inbox',
    description: 'Pull DMARC reports from the central reports@sendvery.com inbox',
)]
final class PollReportsInboxCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly \App\Services\Reports\ReportEmailIngestor $ingestor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sync', null, InputOption::VALUE_NONE, 'Run ingestion inline instead of dispatching to the worker.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('sync')) {
            $count = $this->ingestor->ingestBatch();
            $io->success(sprintf('Ingested %d new envelope(s).', $count));

            return Command::SUCCESS;
        }

        $this->commandBus->dispatch(new PollReportsInbox());
        $io->success('Dispatched central inbox poll.');

        return Command::SUCCESS;
    }
}
