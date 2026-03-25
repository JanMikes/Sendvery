<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\BlacklistCheckResult;
use App\Events\BlacklistCheckCompleted;
use App\Message\CheckBlacklist;
use App\Repository\MonitoredDomainRepository;
use App\Services\BlacklistChecker;
use App\Services\IdentityProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class CheckBlacklistHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private BlacklistChecker $blacklistChecker,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
        private MessageBusInterface $eventBus,
    ) {
    }

    public function __invoke(CheckBlacklist $message): void
    {
        $domain = $this->monitoredDomainRepository->get($message->domainId);
        $result = $this->blacklistChecker->check($message->ipAddress);

        $checkResult = new BlacklistCheckResult(
            id: $this->identityProvider->nextIdentity(),
            monitoredDomain: $domain,
            ipAddress: $message->ipAddress,
            checkedAt: $this->clock->now(),
            results: $result->results,
            isListed: $result->isListed,
        );

        $this->entityManager->persist($checkResult);

        $listedOn = [];
        foreach ($result->results as $dnsbl => $data) {
            if ($data['listed']) {
                $listedOn[] = $dnsbl;
            }
        }

        $this->eventBus->dispatch(new BlacklistCheckCompleted(
            domainId: $domain->id,
            ipAddress: $message->ipAddress,
            isListed: $result->isListed,
            listedOn: $listedOn,
        ));
    }
}
