<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\QuarantinedDmarcReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops quarantined reports past their expires_at — domains that were never
 * verified within the retention window.
 */
#[AsCommand(
    name: 'sendvery:reports:quarantine:purge',
    description: 'Delete quarantined DMARC reports past their TTL',
)]
final class PurgeExpiredQuarantineCommand extends Command
{
    public function __construct(
        private readonly QuarantinedDmarcReportRepository $quarantineRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $expired = $this->quarantineRepository->findExpired($this->clock->now());

        if ([] === $expired) {
            $io->info('No expired quarantined reports.');

            return Command::SUCCESS;
        }

        foreach ($expired as $row) {
            $this->entityManager->remove($row);
        }
        $this->entityManager->flush();

        $io->success(sprintf('Purged %d expired quarantined report(s).', count($expired)));

        return Command::SUCCESS;
    }
}
