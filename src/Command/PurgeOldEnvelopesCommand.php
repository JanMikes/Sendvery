<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ReceivedReportEmailRepository;
use App\Value\Reports\EnvelopeProcessingStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes parsed/ignored/junk envelopes from received_report_email after the
 * configured retention window. Failed envelopes are kept indefinitely so ops
 * can re-run them with sendvery:reports:reprocess after a parser fix.
 *
 * Pending envelopes are NEVER purged — they're rare (mid-flight processing)
 * and dropping them silently would lose data.
 */
#[AsCommand(
    name: 'sendvery:reports:purge',
    description: 'Purge old processed/ignored/junk envelopes from the database',
)]
final class PurgeOldEnvelopesCommand extends Command
{
    private const array PURGEABLE_STATUSES = [
        EnvelopeProcessingStatus::Parsed,
        EnvelopeProcessingStatus::Quarantined,
        EnvelopeProcessingStatus::Ignored,
    ];

    public function __construct(
        private readonly ReceivedReportEmailRepository $envelopeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'int:SENDVERY_ENVELOPE_PURGE_AFTER_DAYS')]
        private readonly int $purgeAfterDays,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cutoff = $this->clock->now()->modify('-'.$this->purgeAfterDays.' days');
        $total = 0;

        foreach (self::PURGEABLE_STATUSES as $status) {
            $batch = $this->envelopeRepository->findOlderThan($cutoff, $status);
            foreach ($batch as $envelope) {
                $this->entityManager->remove($envelope);
                ++$total;
            }
        }

        if (0 === $total) {
            $io->info('No envelopes to purge.');

            return Command::SUCCESS;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Purged %d envelope(s) older than %d days.', $total, $this->purgeAfterDays));

        return Command::SUCCESS;
    }
}
