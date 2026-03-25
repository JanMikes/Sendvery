<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\CheckDomainDns;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'sendvery:dns:check-all',
    description: 'Dispatch DNS checks for all monitored domains',
)]
final class CheckAllDomainsDnsCommand extends Command
{
    public function __construct(
        private readonly Connection $database,
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $domainIds = $this->database->executeQuery(
            'SELECT id FROM monitored_domain ORDER BY created_at',
        )->fetchFirstColumn();

        if ([] === $domainIds) {
            $io->info('No monitored domains found.');

            return Command::SUCCESS;
        }

        foreach ($domainIds as $domainId) {
            $this->commandBus->dispatch(new CheckDomainDns(
                domainId: Uuid::fromString($domainId),
            ));
        }

        $io->success(sprintf('Dispatched DNS checks for %d domain(s).', count($domainIds)));

        return Command::SUCCESS;
    }
}
