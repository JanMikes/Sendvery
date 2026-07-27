<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\SenderIdentityResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retro-fits sender identity onto reports that were ingested before DEC-059
 * (WP2).
 *
 * Every `dmarc_record` written before the enrichment fix carries a null
 * `resolved_hostname`, which is why the dashboard and the weekly digest render
 * raw IPs for historical reports. This command identifies those addresses once
 * — populating the global `sender_identity` cache on the way — and writes the
 * hostname and organisation back onto the records.
 *
 * Deliberately additive: it only ever fills gaps, never deletes and never
 * overwrites an address that already carries enrichment, so it is safe to run
 * repeatedly and safe to interrupt. Run it with `--limit` to work through a
 * large backlog in bites, or `--dry-run` to see what it would learn first.
 */
#[AsCommand(
    name: 'sendvery:senders:backfill-identities',
    description: 'Identify sending hosts on already-ingested DMARC reports and backfill their hostname and organisation',
)]
final class BackfillSenderIdentitiesCommand extends Command
{
    private const int DEFAULT_LIMIT = 500;

    /**
     * Matches the resolver's own per-batch cap, so every address in a chunk gets
     * a real lookup instead of silently falling off the end of the budget and
     * being reported as unresolved.
     */
    private const int CHUNK_SIZE = SenderIdentityResolver::MAX_IDENTIFICATIONS_PER_BATCH;

    public function __construct(
        private readonly Connection $database,
        private readonly EntityManagerInterface $entityManager,
        private readonly SenderIdentityResolver $senderIdentityResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of distinct sending addresses to process in this run',
                (string) self::DEFAULT_LIMIT,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would be identified without writing anything',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');

        if ($limit < 1) {
            $io->error('The --limit option must be a positive number of addresses.');

            return Command::INVALID;
        }

        $dryRun = true === $input->getOption('dry-run');

        $sourceIps = $this->addressesAwaitingIdentity($limit);

        if ([] === $sourceIps) {
            $io->info('Every DMARC record already names its sending host.');

            return Command::SUCCESS;
        }

        $identified = 0;
        $recordsEnriched = 0;

        foreach (array_chunk($sourceIps, self::CHUNK_SIZE) as $chunk) {
            // No auth signals: the role persisted in `sender_identity` is the
            // signal-independent baseline by design, so historical pass rates
            // would change nothing and reading them back would cost a query per
            // address.
            foreach ($this->senderIdentityResolver->resolveMany($chunk) as $resolved) {
                if (!$resolved->isResolved()) {
                    continue;
                }

                ++$identified;
                $recordsEnriched += $dryRun
                    ? $this->countRecordsAwaitingIdentity($resolved->sourceIp)
                    : $this->writeEnrichmentOntoRecords($resolved->sourceIp, $resolved->hostname, $resolved->organization);
            }

            if (!$dryRun) {
                // Flush per chunk so an interrupted run keeps the identities it
                // has already paid DNS lookups for.
                $this->entityManager->flush();
            }
        }

        if ($dryRun) {
            $io->info(sprintf(
                'Dry run, nothing written: would identify %d of %d address(es) and name %d record(s).',
                $identified,
                count($sourceIps),
                $recordsEnriched,
            ));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Identified %d of %d address(es); named the sender on %d record(s).',
            $identified,
            count($sourceIps),
            $recordsEnriched,
        ));

        return Command::SUCCESS;
    }

    /**
     * Addresses that still show up as raw IPs on the dashboard, plus those
     * whose identity is missing an axis added since they were cached.
     *
     * Addresses with no `sender_identity` row yet come first: an address with no
     * PTR record stays unenriched forever, so ordering by address alone would
     * let a block of unresolvable hosts consume every run's budget and starve
     * the ones that would actually resolve.
     *
     * The `asn_resolved_at` and `dnswl_checked_at` tests are the DEC-060 half.
     * A row cached before those lookups existed is perfectly enriched by the old
     * definition and would never be revisited by the hostname test alone. Those
     * rows do self-heal on their next ingest —
     * {@see \App\Entity\SenderIdentity::isDueForRetry()} makes them due exactly
     * once — but an operator should not have to wait for a sender to send again
     * to finish a migration.
     *
     * @return list<string>
     */
    private function addressesAwaitingIdentity(int $limit): array
    {
        $rows = $this->database->executeQuery(
            'SELECT DISTINCT rec.source_ip, si.id IS NULL AS never_looked_up
            FROM dmarc_record rec
            LEFT JOIN sender_identity si ON si.source_ip = rec.source_ip
            WHERE rec.resolved_hostname IS NULL
               OR si.id IS NULL
               OR si.asn_resolved_at IS NULL
               OR si.dnswl_checked_at IS NULL
            ORDER BY never_looked_up DESC, rec.source_ip
            LIMIT :limit',
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        )->fetchFirstColumn();

        return array_map(strval(...), $rows);
    }

    private function countRecordsAwaitingIdentity(string $sourceIp): int
    {
        return (int) $this->database->fetchOne(
            'SELECT COUNT(*) FROM dmarc_record WHERE source_ip = :sourceIp AND resolved_hostname IS NULL',
            ['sourceIp' => $sourceIp],
        );
    }

    private function writeEnrichmentOntoRecords(string $sourceIp, ?string $hostname, ?string $organization): int
    {
        return (int) $this->database->executeStatement(
            'UPDATE dmarc_record SET resolved_hostname = :hostname, resolved_org = :organization
            WHERE source_ip = :sourceIp AND resolved_hostname IS NULL',
            [
                'sourceIp' => $sourceIp,
                'hostname' => $hostname,
                'organization' => $organization,
            ],
        );
    }
}
