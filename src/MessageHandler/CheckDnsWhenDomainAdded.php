<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DomainAdded;
use App\Message\CheckDomainDns;
use App\Message\SnapshotDomainHealth;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

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

        // The first health snapshot has to be queued here too, otherwise the
        // grade / score / category surfaces stay empty until the 03:00 cron
        // even though the check itself already ran minutes after the domain was
        // added — the user sees "no health snapshots yet" on a domain we have
        // fully checked.
        //
        // SnapshotDomainHealth has no routing entry (it is a cheap read + one
        // INSERT, handled synchronously at its other call sites right after a
        // flush). Dispatching it plainly here would run it BEFORE the async
        // check it is meant to summarise, so it is pushed onto the same `async`
        // transport with an explicit stamp: the Doctrine transport is FIFO, so
        // it is picked up after the check that was enqueued a moment earlier.
        // A concurrent-worker race can only produce a snapshot one check older;
        // the nightly sweep re-snapshots every domain anyway.
        $this->commandBus->dispatch(
            new SnapshotDomainHealth(domainId: $event->domainId),
            [new TransportNamesStamp(['async'])],
        );
    }
}
