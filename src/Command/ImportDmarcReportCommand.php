<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ProcessDmarcReport;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dmarc\ReportAttachmentExtractor;
use App\Services\IdentityProvider;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'sendvery:dmarc:import',
    description: 'Import a DMARC aggregate report from a file',
)]
final class ImportDmarcReportCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly ReportAttachmentExtractor $extractor,
        private readonly IdentityProvider $identityProvider,
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to .xml, .zip, or .gz DMARC report file')
            ->addOption('domain-id', null, InputOption::VALUE_REQUIRED, 'UUID of the monitored domain');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filePath = $input->getArgument('file');
        assert(is_string($filePath));

        if (!file_exists($filePath)) {
            $io->error(sprintf('File not found: %s', $filePath));

            return Command::FAILURE;
        }

        $domainIdString = $input->getOption('domain-id');
        if (!is_string($domainIdString)) {
            $io->error('The --domain-id option is required.');

            return Command::FAILURE;
        }

        $domainId = \Ramsey\Uuid\Uuid::fromString($domainIdString);

        // Verify domain exists
        $this->monitoredDomainRepository->get($domainId);

        $content = file_get_contents($filePath);
        if ($content === false) { // @codeCoverageIgnore
            $io->error(sprintf('Could not read file: %s', $filePath)); // @codeCoverageIgnore
            return Command::FAILURE; // @codeCoverageIgnore
        }

        $filename = basename($filePath);
        $xmlFiles = $this->extractor->extract($content, $filename);

        $io->info(sprintf('Found %d XML report(s) in %s', count($xmlFiles), $filename));

        foreach ($xmlFiles as $index => $xmlContent) {
            $reportId = $this->identityProvider->nextIdentity();

            $this->commandBus->dispatch(new ProcessDmarcReport(
                reportId: $reportId,
                domainId: $domainId,
                xmlContent: $xmlContent,
            ));

            $this->entityManager->flush();

            $io->success(sprintf('Report #%d imported with ID: %s', $index + 1, $reportId->toString()));
        }

        return Command::SUCCESS;
    }
}
