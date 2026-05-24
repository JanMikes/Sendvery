<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\DomainHealthSnapshot;
use App\Message\SnapshotDomainHealth;
use App\Repository\DnsCheckResultRepository;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\HealthSnapshotComposer;
use App\Services\IdentityProvider;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SnapshotDomainHealthHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private DnsCheckResultRepository $dnsCheckResultRepository,
        private HealthSnapshotComposer $composer,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SnapshotDomainHealth $message): void
    {
        $domain = $this->monitoredDomainRepository->get($message->domainId);

        $spf = $this->dnsCheckResultRepository->findLatestForDomainAndType($message->domainId, DnsCheckType::Spf);
        $dkim = $this->dnsCheckResultRepository->findLatestForDomainAndType($message->domainId, DnsCheckType::Dkim);
        $dmarc = $this->dnsCheckResultRepository->findLatestForDomainAndType($message->domainId, DnsCheckType::Dmarc);
        $mx = $this->dnsCheckResultRepository->findLatestForDomainAndType($message->domainId, DnsCheckType::Mx);

        $composition = $this->composer->compose($spf, $dkim, $dmarc, $mx);

        $snapshot = new DomainHealthSnapshot(
            id: $this->identityProvider->nextIdentity(),
            monitoredDomain: $domain,
            grade: $composition->grade,
            score: $composition->score,
            spfScore: $composition->spfScore,
            dkimScore: $composition->dkimScore,
            dmarcScore: $composition->dmarcScore,
            mxScore: $composition->mxScore,
            blacklistScore: $composition->blacklistScore,
            checkedAt: $this->clock->now(),
            recommendations: [],
            shareHash: bin2hex(random_bytes(16)),
        );

        $this->entityManager->persist($snapshot);
    }
}
