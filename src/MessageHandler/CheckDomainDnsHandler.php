<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckDomainDns;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\DnsMonitor;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckDomainDnsHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private DnsMonitor $dnsMonitor,
    ) {
    }

    public function __invoke(CheckDomainDns $message): void
    {
        $domain = $this->monitoredDomainRepository->get($message->domainId);
        $results = $this->dnsMonitor->check($domain);

        foreach ($results as $result) {
            $this->entityManager->persist($result);

            if (!$result->isValid) {
                continue;
            }

            match ($result->type) {
                DnsCheckType::Spf => $domain->spfVerifiedAt = $result->checkedAt,
                DnsCheckType::Dkim => $domain->dkimVerifiedAt = $result->checkedAt,
                DnsCheckType::Dmarc => $domain->dmarcVerifiedAt = $result->checkedAt,
                DnsCheckType::Mx => null,
            };
        }
    }
}
