<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Events\DmarcPolicyChanged;
use App\Services\Dns\DnsRecordPublisher;
use App\Services\Dns\ManagedDmarcPolicyComposer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The single republish path for set / advance / rollback / downgrade-freeze.
 * Recomposes the expected content from the now-current policy and upserts the
 * hosted TXT (content-compare, single-record invariant). On failure the id is
 * left as-is and the sync cron reconciles (the client captured to Sentry).
 */
#[AsMessageHandler]
final readonly class UpdateHostedDmarcRecordWhenPolicyChanged
{
    public function __construct(
        private DnsRecordPublisher $dnsRecordPublisher,
        private ManagedDmarcPolicyComposer $composer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DmarcPolicyChanged $event): void
    {
        $domain = $this->entityManager->find(MonitoredDomain::class, $event->domainId);
        if (null === $domain) {
            return;
        }

        $policy = $domain->currentManagedPolicy();
        if (null === $policy) {
            return;
        }

        $recordId = $this->dnsRecordPublisher->publishPolicyRecord(
            $domain->domain,
            $this->composer->compose($policy),
        );

        if (null === $recordId) {
            $this->logger->warning('Could not update hosted DMARC record for {domain} — will be reconciled by sync cron', [
                'domain' => $domain->domain,
            ]);

            return;
        }

        $domain->cloudflareHostedDmarcRecordId = $recordId;
    }
}
