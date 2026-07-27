<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CheckDomainDns;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\DnsMonitor;
use App\Services\Dns\ManagedDmarcCnameChecker;
use App\Value\Dns\DmarcSetupMode;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckDomainDnsHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private DnsMonitor $dnsMonitor,
        private ManagedDmarcCnameChecker $cnameChecker,
        private ClockInterface $clock,
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

            // These timestamps exist for the verification/quarantine-release
            // rules, NOT as the source of truth for "is this record healthy?" —
            // that is read straight off the `dns_check_result` rows persisted
            // above (see App\Query\GetLatestDnsCheckStates). MX therefore needs
            // no column of its own; it never gates verification.
            match ($result->type) {
                DnsCheckType::Spf => $domain->spfVerifiedAt = $result->checkedAt,
                DnsCheckType::Dkim => $domain->dkimVerifiedAt = $result->checkedAt,
                DnsCheckType::Dmarc => $domain->markDmarcVerified($result->checkedAt),
                DnsCheckType::Mx => null,
            };
        }

        // Managed DMARC (DEC-058): keep cnameVerifiedAt fresh on the daily sweep.
        // A non-verified outcome clears the flag, which freezes the auto-ramp
        // until the CNAME is restored.
        if (DmarcSetupMode::ManagedCname === $domain->dmarcSetupMode) {
            $domain->markCnameVerified($this->cnameChecker->verify($domain->domain), $this->clock->now());
        }
    }
}
