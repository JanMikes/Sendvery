<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MagicLinkTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes magic-link tokens past the forensic retention window. A row is
 * created for every sign-in attempt — including every address a signup-abuse
 * bot ever submitted — and nothing else removes them, so without this the
 * table is an unbounded, permanent log of every email ever typed into the
 * login form. Thirty days keeps the requested_ip/requested_user_agent trail
 * long enough to investigate a campaign (the July 2026 one was spotted within
 * four weeks) while making sure abandoned sign-in attempts don't sit in the
 * database forever. Tokens themselves expire after 15 minutes, so nothing
 * this deletes is redeemable.
 */
#[AsCommand(
    name: 'sendvery:auth:purge-magic-links',
    description: 'Purge magic-link tokens older than the retention window',
)]
final class PurgeExpiredMagicLinkTokensCommand extends Command
{
    private const int RETENTION_DAYS = 30;

    public function __construct(
        private readonly MagicLinkTokenRepository $magicLinkTokenRepository,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $cutoff = $this->clock->now()->modify('-'.self::RETENTION_DAYS.' days');
        $deleted = $this->magicLinkTokenRepository->deleteCreatedBefore($cutoff);

        if (0 === $deleted) {
            $io->info('No magic-link tokens to purge.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Purged %d magic-link token(s) older than %d days.', $deleted, self::RETENTION_DAYS));

        return Command::SUCCESS;
    }
}
