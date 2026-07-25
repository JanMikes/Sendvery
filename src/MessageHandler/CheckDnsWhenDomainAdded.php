<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DomainAdded;
use App\Message\CheckDomainDns;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the FIRST DNS check the moment a domain is added, instead of leaving
 * the domain unchecked until the nightly `sendvery:dns:check-all` sweep (which
 * could be up to ~24h away). The check itself runs on the async transport —
 * a DKIM selector brute-force against a slow nameserver can take long enough
 * that it must never run inside the add-domain web request.
 */
#[AsMessageHandler]
final readonly class CheckDnsWhenDomainAdded
{
    public function __construct(
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(DomainAdded $event): void
    {
        $this->commandBus->dispatch(new CheckDomainDns(domainId: $event->domainId));
    }
}
