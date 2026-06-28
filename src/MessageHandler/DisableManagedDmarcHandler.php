<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DisableManagedDmarc;
use App\Repository\MonitoredDomainRepository;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Switch a domain back to self-TXT. No entitlement gate — taking control back
 * must always work, even for a frozen (downgraded) team. Teardown of the hosted
 * record is dangling-safe (handled by RemoveHostedDmarcRecordWhenManagedDisabled).
 */
#[AsMessageHandler]
final readonly class DisableManagedDmarcHandler
{
    public function __construct(
        private MonitoredDomainRepository $monitoredDomainRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DisableManagedDmarc $message): void
    {
        $domain = $this->monitoredDomainRepository->findForTeams(
            $message->domainId,
            [Uuid::fromString($message->teamId)],
        );

        if (null === $domain) {
            throw new \RuntimeException('Domain not found or not owned by team.');
        }

        $domain->disableManagedDmarc($this->clock->now());
    }
}
