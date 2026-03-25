<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\PollMailbox;
use App\Repository\MailboxConnectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'sendvery:mailbox:poll',
    description: 'Poll all active mailbox connections for DMARC reports',
)]
final class PollMailboxesCommand extends Command
{
    public function __construct(
        private readonly MailboxConnectionRepository $connectionRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Poll a specific connection by UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $connectionId = $input->getOption('connection');

        if (is_string($connectionId)) {
            $uuid = \Ramsey\Uuid\Uuid::fromString($connectionId);
            $connection = $this->connectionRepository->get($uuid);

            $this->commandBus->dispatch(new PollMailbox(connectionId: $connection->id));
            $io->success(sprintf('Dispatched poll for connection: %s', $connection->id->toString()));

            return Command::SUCCESS;
        }

        $connections = $this->connectionRepository->findActiveConnections();

        if ([] === $connections) {
            $io->info('No active mailbox connections found.');

            return Command::SUCCESS;
        }

        foreach ($connections as $connection) {
            $this->commandBus->dispatch(new PollMailbox(connectionId: $connection->id));
            $io->info(sprintf('Dispatched poll for connection: %s (%s)', $connection->id->toString(), $connection->host));
        }

        $io->success(sprintf('Dispatched poll for %d connection(s).', count($connections)));

        return Command::SUCCESS;
    }
}
