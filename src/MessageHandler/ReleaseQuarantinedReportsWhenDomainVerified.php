<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DomainDmarcVerified;
use App\Message\ReleaseQuarantinedReportsForDomain;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Listens for the first DMARC verification of a domain and asks the worker
 * to release any reports we'd parked in quarantine while the team was still
 * setting up DNS. This is what makes "verify DNS, see your reports
 * immediately" work even for reports that arrived during the wait.
 */
#[AsMessageHandler]
final readonly class ReleaseQuarantinedReportsWhenDomainVerified
{
    public function __construct(
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(DomainDmarcVerified $event): void
    {
        $this->commandBus->dispatch(new ReleaseQuarantinedReportsForDomain(
            domainId: $event->domainId,
            domainName: $event->domainName,
        ));
    }
}
