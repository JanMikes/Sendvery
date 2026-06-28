<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MonitoredDomain;
use App\Events\ManagedDmarcDanglingDetected;
use App\Services\Dns\CloudflareDnsClient;
use App\Services\Dns\DnsRecordPublisher;
use App\Services\Dns\ManagedDmarcCnameChecker;
use App\Services\Dns\ManagedDmarcPolicyComposer;
use App\Value\AlertType;
use App\Value\Dns\CnameVerificationOutcome;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reconciles the hosted managed-DMARC policy TXT records with the active managed
 * domains (DEC-058). A sibling of sendvery:dns:sync-authorization-records (kept
 * separate to avoid the _report._dmarc / _dmarc collision in one loop). For each
 * managed domain it recreates a missing record, re-publishes drifted content
 * (recovering dropped enable side-effects), and reconciles a stale id. For
 * offboarded (self-TXT) domains with a lingering hosted record it tears down
 * ONLY once the CNAME no longer points at us — while the CNAME still resolves to
 * Sendvery the record is kept and a dangling alert is raised, so a customer's
 * live DMARC never goes dark. Per-domain try/catch + Sentry.
 */
#[AsCommand(
    name: 'sendvery:dmarc:sync-hosted-records',
    description: 'Reconcile hosted managed-DMARC policy records: recreate/repair drifted records, dangling-safe teardown',
)]
final class SyncHostedDmarcRecordsCommand extends Command
{
    private const int DANGLING_DEDUP_DAYS = 7;

    public function __construct(
        private readonly CloudflareDnsClient $cloudflareClient,
        private readonly DnsRecordPublisher $dnsRecordPublisher,
        private readonly ManagedDmarcPolicyComposer $composer,
        private readonly ManagedDmarcCnameChecker $cnameChecker,
        private readonly Connection $database,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->cloudflareClient->isConfigured()) {
            $io->info('Cloudflare credentials not configured — skipping hosted-record sync.');

            return Command::SUCCESS;
        }

        $reconciled = 0;
        $tornDown = 0;
        $dangling = 0;
        $deferred = 0;

        foreach ($this->domainIdsToSync() as $id) {
            try {
                $outcome = $this->syncDomain(Uuid::fromString($id));
                $reconciled += 'reconciled' === $outcome ? 1 : 0;
                $tornDown += 'torn_down' === $outcome ? 1 : 0;
                $dangling += 'dangling' === $outcome ? 1 : 0;
                // publish/delete failures and unconfirmed lookups all leave the DB
                // markers in place so the domain is re-selected and retried next run.
                $deferred += in_array($outcome, ['publish_failed', 'delete_failed', 'lookup_failed'], true) ? 1 : 0;
            } catch (\Throwable $e) {
                \Sentry\captureException($e);
                $io->warning(sprintf('Hosted-record sync failed for domain %s: %s', $id, $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'Hosted-record sync complete: %d reconciled, %d torn down, %d dangling, %d deferred (retried next run).',
            $reconciled,
            $tornDown,
            $dangling,
            $deferred,
        ));

        return Command::SUCCESS;
    }

    private function syncDomain(UuidInterface $domainId): string
    {
        $domain = $this->entityManager->find(MonitoredDomain::class, $domainId);
        if (null === $domain) {
            return 'skipped';
        }

        if (DmarcSetupMode::ManagedCname === $domain->dmarcSetupMode) {
            return $this->reconcileManaged($domain);
        }

        return $this->teardownIfSafe($domain);
    }

    private function reconcileManaged(MonitoredDomain $domain): string
    {
        $policy = $domain->currentManagedPolicy();
        if (null === $policy) {
            return 'skipped';
        }

        $expected = $this->composer->compose($policy);
        $current = $this->dnsRecordPublisher->findPolicyRecord($domain->domain);

        if (null === $current || trim($current->content) !== trim($expected)) {
            // Missing (recover a dropped enable side-effect) or drifted — (re)publish.
            $recordId = $this->dnsRecordPublisher->publishPolicyRecord($domain->domain, $expected);
            if (null === $recordId) {
                // Publish failed/aborted (already logged + Sentry-captured). Report it
                // distinctly instead of counting a no-op as reconciled; retried next run.
                return 'publish_failed';
            }

            $domain->cloudflareHostedDmarcRecordId = $recordId;
            $this->entityManager->flush();

            return 'reconciled';
        }

        if ($domain->cloudflareHostedDmarcRecordId !== $current->id) {
            $domain->cloudflareHostedDmarcRecordId = $current->id;
            $this->entityManager->flush();

            return 'reconciled';
        }

        return 'in_sync';
    }

    private function teardownIfSafe(MonitoredDomain $domain): string
    {
        // Dangling-safe: never delete while the CNAME still resolves to us — that
        // would NXDOMAIN the customer's _dmarc and break live DMARC.
        $outcome = $this->cnameChecker->verify($domain->domain);
        if (CnameVerificationOutcome::Verified === $outcome) {
            if (!$this->hasRecentDanglingAlert($domain->id->toString())) {
                $this->bus->dispatch(new ManagedDmarcDanglingDetected($domain->id, $domain->team->id, $domain->domain));
            }

            return 'dangling';
        }

        if (CnameVerificationOutcome::LookupFailed === $outcome) {
            // Could not confirm the CNAME is gone — deleting now could dark a still-
            // delegated customer's DMARC. Defer; the next run retries once DNS answers.
            return 'lookup_failed';
        }

        if (!$this->dnsRecordPublisher->removePolicyRecord($domain->domain)) {
            // The delete failed (already logged + Sentry-captured by the client).
            // Leave the DB markers set so domainIdsToSync() re-selects this domain
            // next run, and report it distinctly so the summary isn't misleading.
            return 'delete_failed';
        }

        $domain->cloudflareHostedDmarcRecordId = null;
        $domain->hostedDmarcTeardownAt = null;
        $this->entityManager->flush();

        return 'torn_down';
    }

    /** @return list<string> */
    private function domainIdsToSync(): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->executeQuery(
            "SELECT id FROM monitored_domain
             WHERE dmarc_setup_mode = 'managed_cname'
                OR cloudflare_hosted_dmarc_record_id IS NOT NULL
                OR hosted_dmarc_teardown_at IS NOT NULL
             ORDER BY created_at",
        )->fetchFirstColumn();

        return $ids;
    }

    private function hasRecentDanglingAlert(string $domainId): bool
    {
        $count = $this->database->executeQuery(
            'SELECT COUNT(*) FROM alert
             WHERE monitored_domain_id = :domainId
               AND type = :type
               AND created_at > NOW() - (:days || \' days\')::interval',
            [
                'domainId' => $domainId,
                'type' => AlertType::ManagedDmarcDangling->value,
                'days' => self::DANGLING_DEDUP_DAYS,
            ],
        )->fetchOne();

        return (int) $count > 0;
    }
}
