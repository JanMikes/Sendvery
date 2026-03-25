<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sendvery:dmarc:summary',
    description: 'Show DMARC report summary statistics',
)]
final class DmarcSummaryCommand extends Command
{
    public function __construct(
        private readonly Connection $database,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Number of days to look back', '7')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Filter by domain name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('days');
        $domainFilter = $input->getOption('domain');

        $params = ['days' => $days];
        $domainClause = '';
        if (is_string($domainFilter)) {
            $domainClause = 'AND md.domain = :domain';
            $params['domain'] = $domainFilter;
        }

        $summary = $this->database->executeQuery(
            sprintf(
                "SELECT
                    COUNT(DISTINCT dr.id) AS total_reports,
                    COALESCE(SUM(rec.count), 0) AS total_messages,
                    COALESCE(SUM(CASE WHEN rec.dkim_result = 'pass' OR rec.spf_result = 'pass' THEN rec.count ELSE 0 END), 0) AS pass_messages,
                    COALESCE(SUM(CASE WHEN rec.dkim_result != 'pass' AND rec.spf_result != 'pass' THEN rec.count ELSE 0 END), 0) AS fail_messages
                FROM dmarc_report dr
                JOIN monitored_domain md ON md.id = dr.monitored_domain_id
                LEFT JOIN dmarc_record rec ON rec.dmarc_report_id = dr.id
                WHERE dr.date_range_end >= NOW() - INTERVAL '%d days'
                %s",
                $days,
                $domainClause,
            ),
            $params,
        )->fetchAssociative();

        if ($summary === false || (int) $summary['total_reports'] === 0) {
            $io->warning(sprintf('No DMARC reports found in the last %d days.', $days));

            return Command::SUCCESS;
        }

        $totalMessages = (int) $summary['total_messages'];
        $passMessages = (int) $summary['pass_messages'];
        $failMessages = (int) $summary['fail_messages'];
        $passRate = $totalMessages > 0 ? round($passMessages / $totalMessages * 100, 1) : 0;

        $io->title(sprintf('DMARC Summary — Last %d days%s', $days, is_string($domainFilter) ? " ({$domainFilter})" : ''));
        $io->listing([
            sprintf('Reports: %d', (int) $summary['total_reports']),
            sprintf('Total messages: %d', $totalMessages),
            sprintf('Pass: %d (%.1f%%)', $passMessages, $passRate),
            sprintf('Fail: %d (%.1f%%)', $failMessages, $totalMessages > 0 ? 100 - $passRate : 0),
        ]);

        $topSenders = $this->database->executeQuery(
            sprintf(
                "SELECT
                    rec.source_ip,
                    SUM(rec.count) AS total,
                    SUM(CASE WHEN rec.dkim_result = 'pass' OR rec.spf_result = 'pass' THEN rec.count ELSE 0 END) AS pass_count
                FROM dmarc_record rec
                JOIN dmarc_report dr ON dr.id = rec.dmarc_report_id
                JOIN monitored_domain md ON md.id = dr.monitored_domain_id
                WHERE dr.date_range_end >= NOW() - INTERVAL '%d days'
                %s
                GROUP BY rec.source_ip
                ORDER BY total DESC
                LIMIT 10",
                $days,
                $domainClause,
            ),
            $params,
        )->fetchAllAssociative();

        if ($topSenders !== []) {
            $io->section('Top senders');
            $rows = array_map(static fn (array $row): array => [
                $row['source_ip'],
                $row['total'],
                $row['pass_count'],
                (int) $row['total'] > 0 ? round((int) $row['pass_count'] / (int) $row['total'] * 100, 1) . '%' : '0%',
            ], $topSenders);
            $io->table(['IP', 'Messages', 'Pass', 'Rate'], $rows);
        }

        return Command::SUCCESS;
    }
}
