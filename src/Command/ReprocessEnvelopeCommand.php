<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ProcessReceivedReportEmail;
use App\Repository\ReceivedReportEmailRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Re-runs ProcessReceivedReportEmail for a specific envelope, typically a
 * `failed` row that the worker couldn't parse before but should now after a
 * parser fix. Useful in an ops "fix parser → reprocess → verify" loop.
 *
 *   bin/console sendvery:reports:reprocess <envelope-id>
 */
#[AsCommand(
    name: 'sendvery:reports:reprocess',
    description: 'Re-run processing for a specific ReceivedReportEmail envelope',
)]
final class ReprocessEnvelopeCommand extends Command
{
    public function __construct(
        private readonly ReceivedReportEmailRepository $envelopeRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('envelope-id', InputArgument::REQUIRED, 'UUID of the received_report_email row to re-process.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rawId = $input->getArgument('envelope-id');
        assert(is_string($rawId));

        if (!Uuid::isValid($rawId)) {
            $io->error('Envelope id must be a UUID.');

            return Command::FAILURE;
        }

        $envelopeId = Uuid::fromString($rawId);

        // Touch the repository so the user gets a clear error if the id doesn't exist,
        // instead of a silent retry-loop failure inside the messenger worker.
        $envelope = $this->envelopeRepository->get($envelopeId);

        $this->commandBus->dispatch(new ProcessReceivedReportEmail(envelopeId: $envelope->id));

        $io->success(sprintf('Re-dispatched processing for envelope %s (was: %s).', $envelope->id->toString(), $envelope->processingStatus->value));

        return Command::SUCCESS;
    }
}
