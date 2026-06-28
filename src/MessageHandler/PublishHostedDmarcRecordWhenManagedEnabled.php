<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Events\ManagedDmarcEnabled;
use App\Services\Dns\DnsRecordPublisher;
use App\Services\Dns\ManagedDmarcPolicyComposer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Publish-first: when managed DMARC is enabled, publish the hosted policy TXT
 * BEFORE the customer points the CNAME at us, so the CNAME target always exists
 * (no NXDOMAIN window). On failure the record id stays null and the sync cron
 * recovers it (the client already captured the failure to Sentry).
 */
#[AsMessageHandler]
final readonly class PublishHostedDmarcRecordWhenManagedEnabled
{
    public function __construct(
        private DnsRecordPublisher $dnsRecordPublisher,
        private ManagedDmarcPolicyComposer $composer,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ManagedDmarcEnabled $event): void
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
            $this->logger->warning('Could not publish hosted DMARC record for {domain} — will be retried by sync cron', [
                'domain' => $domain->domain,
            ]);

            return;
        }

        $domain->cloudflareHostedDmarcRecordId = $recordId;
    }
}
