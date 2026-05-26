<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Dns\CloudflareDnsClient;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sendvery:dns:sync-authorization-records',
    description: 'Reconcile Cloudflare RFC 7489 authorization TXT records with active monitored domains',
)]
final class SyncAuthorizationRecordsCommand extends Command
{
    public function __construct(
        private readonly CloudflareDnsClient $cloudflareClient,
        private readonly Connection $database,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->cloudflareClient->isConfigured()) {
            $io->info('Cloudflare credentials not configured — skipping sync.');

            return Command::SUCCESS;
        }

        $activeDomains = $this->getActiveDomains();
        $cloudflareRecords = $this->cloudflareClient->listAuthorizationRecords();

        $created = 0;
        $deleted = 0;
        $reconciled = 0;

        $cloudflareByDomain = [];
        foreach ($cloudflareRecords as $record) {
            $customerDomain = $this->cloudflareClient->extractCustomerDomain($record);
            if (null !== $customerDomain) {
                $cloudflareByDomain[strtolower($customerDomain)] = $record;
            }
        }

        foreach ($activeDomains as $row) {
            $domainName = strtolower($row['domain']);

            if (isset($cloudflareByDomain[$domainName])) {
                if (null === $row['cloudflare_auth_record_id'] || $row['cloudflare_auth_record_id'] !== $cloudflareByDomain[$domainName]->id) {
                    $this->database->executeStatement(
                        'UPDATE monitored_domain SET cloudflare_auth_record_id = :recordId WHERE id = :id',
                        ['recordId' => $cloudflareByDomain[$domainName]->id, 'id' => $row['id']],
                    );
                    ++$reconciled;
                }
                unset($cloudflareByDomain[$domainName]);

                continue;
            }

            $recordId = $this->cloudflareClient->publishAuthorizationRecord($domainName);
            if (null !== $recordId) {
                $this->database->executeStatement(
                    'UPDATE monitored_domain SET cloudflare_auth_record_id = :recordId WHERE id = :id',
                    ['recordId' => $recordId, 'id' => $row['id']],
                );
                ++$created;
            }
        }

        foreach ($cloudflareByDomain as $record) {
            if ($this->cloudflareClient->deleteRecordById($record->id)) {
                ++$deleted;
            }
        }

        $io->success(sprintf(
            'Sync complete: %d created, %d deleted, %d reconciled (%d active domains, %d Cloudflare records).',
            $created,
            $deleted,
            $reconciled,
            count($activeDomains),
            count($cloudflareRecords),
        ));

        return Command::SUCCESS;
    }

    /** @return list<array{id: string, domain: string, cloudflare_auth_record_id: ?string}> */
    private function getActiveDomains(): array
    {
        /** @var list<array{id: string, domain: string, cloudflare_auth_record_id: ?string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT id, domain, cloudflare_auth_record_id FROM monitored_domain ORDER BY created_at',
        )->fetchAllAssociative();

        return $rows;
    }
}
