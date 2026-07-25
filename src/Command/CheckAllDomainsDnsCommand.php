<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\CheckDomainDns;
use App\Message\SnapshotDomainHealth;
use App\MessageHandler\CheckDomainDnsHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'sendvery:dns:check-all',
    description: 'Run DNS checks and health snapshots for all monitored domains',
)]
final class CheckAllDomainsDnsCommand extends Command
{
    public function __construct(
        private readonly Connection $database,
        private readonly MessageBusInterface $commandBus,
        private readonly CheckDomainDnsHandler $checkDomainDnsHandler,
        private readonly EntityManagerInterface $entityManager,
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
            $domainUuid = Uuid::fromString($domainId);

            // CheckDomainDns is routed to the async transport (so the
            // add-domain flow can queue a first check without blocking the web
            // request), but the nightly sweep must stay synchronous: the
            // snapshot below reads the check results this run just wrote, and
            // the wrapping Sentry monitor should measure the actual work.
            // Direct invocation + explicit flush mirrors ReverifyDomainController.
            ($this->checkDomainDnsHandler)(new CheckDomainDns(domainId: $domainUuid));
            $this->entityManager->flush();

            $this->commandBus->dispatch(new SnapshotDomainHealth(
                domainId: $domainUuid,
            ));
        }

        $io->success(sprintf('Checked DNS for %d domain(s).', count($domainIds)));

        return Command::SUCCESS;
    }
}
