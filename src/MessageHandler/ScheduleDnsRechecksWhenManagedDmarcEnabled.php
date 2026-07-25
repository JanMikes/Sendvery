<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\ManagedDmarcEnabled;
use App\Message\CheckDomainDns;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * After enabling managed DMARC the user leaves to add the CNAME at their DNS
 * host. Without these delayed re-checks, `cnameVerifiedAt` would only flip on
 * the nightly sweep — leaving the card on "CNAME pending" for hours after the
 * record was actually published. The delay ladder covers the typical "add the
 * record within minutes" case plus a slower-propagation retry; anything later
 * is caught by the daily sweep or the manual "Verify now" button.
 */
#[AsMessageHandler]
final readonly class ScheduleDnsRechecksWhenManagedDmarcEnabled
{
    /** @var list<int> Delays in seconds: 5 min, 30 min, 2 h. */
    private const array RECHECK_DELAYS = [300, 1_800, 7_200];

    public function __construct(
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(ManagedDmarcEnabled $event): void
    {
        foreach (self::RECHECK_DELAYS as $delaySeconds) {
            $this->commandBus->dispatch(
                new CheckDomainDns(domainId: $event->domainId),
                [new DelayStamp($delaySeconds * 1000)],
            );
        }
    }
}
