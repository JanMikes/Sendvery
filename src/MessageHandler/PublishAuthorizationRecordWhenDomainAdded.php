<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Events\DomainAdded;
use App\Services\Dns\DnsRecordPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PublishAuthorizationRecordWhenDomainAdded
{
    public function __construct(
        private DnsRecordPublisher $dnsRecordPublisher,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DomainAdded $event): void
    {
        $domain = $this->entityManager->find(MonitoredDomain::class, $event->domainId);
        if (null === $domain) {
            return;
        }

        $recordId = $this->dnsRecordPublisher->publishAuthorizationRecord($domain->domain);

        if (null === $recordId) {
            $this->logger->warning('Could not publish authorization record for domain {domain} — will be retried by sync cron', [
                'domain' => $domain->domain,
            ]);

            return;
        }

        $domain->cloudflareAuthRecordId = $recordId;
    }
}
