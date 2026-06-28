<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Events\ManagedDmarcDisabled;
use App\Services\Dns\DnsRecordPublisher;
use App\Services\Dns\ManagedDmarcCnameChecker;
use App\Value\Dns\CnameVerificationOutcome;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Dangling-safe teardown: only delete the hosted policy TXT once the customer's
 * CNAME no longer points at us — deleting while the CNAME still resolves to our
 * record would NXDOMAIN their _dmarc and break live DMARC. While the CNAME
 * persists we keep the record and defer to the sync cron.
 */
#[AsMessageHandler]
final readonly class RemoveHostedDmarcRecordWhenManagedDisabled
{
    public function __construct(
        private DnsRecordPublisher $dnsRecordPublisher,
        private ManagedDmarcCnameChecker $cnameChecker,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ManagedDmarcDisabled $event): void
    {
        $domain = $this->entityManager->find(MonitoredDomain::class, $event->domainId);
        if (null === $domain) {
            return;
        }

        if (CnameVerificationOutcome::Verified === $this->cnameChecker->verify($domain->domain)) {
            // CNAME still points at us — keep serving DMARC; the sync cron tears
            // down once the customer removes the CNAME.
            return;
        }

        if ($this->dnsRecordPublisher->removePolicyRecord($domain->domain)) {
            $domain->cloudflareHostedDmarcRecordId = null;
            $domain->hostedDmarcTeardownAt = null;
        }
    }
}
