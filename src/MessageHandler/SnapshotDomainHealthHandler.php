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

        // A snapshot is a graded VERDICT about a domain, so it may only be
        // written once at least one DNS check has actually produced a row.
        // With no rows at all, HealthSnapshotComposer scores every protocol 0
        // and grades the domain F — a definite failure invented out of "we
        // have not looked yet", published at `/health/{shareHash}` as an 8xl
        // red F to anyone with the link.
        //
        // This is reachable: CheckDnsWhenDomainAdded enqueues CheckDomainDns
        // and SnapshotDomainHealth onto the same async transport and relies on
        // FIFO ordering, but CheckDomainDns retries with backoff (5s → 30s →
        // 3m → 15m) and concurrent workers can invert the pair. Refusing to
        // snapshot is the safe outcome: every check path dispatches
        // SnapshotDomainHealth again once it has written its rows, and the
        // health page already renders an honest "No health score yet."
        if (null === $spf && null === $dkim && null === $dmarc && null === $mx) {
            return;
        }

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
