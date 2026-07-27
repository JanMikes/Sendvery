<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DnsCheckCompleted;
use App\Repository\AlertRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Closes the loop on DNS incidents: once a record validates again, the
 * "{TYPE} is broken" / "{TYPE} record removed" alerts it produced no longer
 * describe reality and must stop demanding attention.
 *
 * Runs alongside {@see AlertOnDnsChange} on the same event. The two never
 * collide because this handler only ever touches the *problem* alert types —
 * see {@see AlertRepository::findUnresolvedDnsProblemsForDomain()}.
 */
#[AsMessageHandler]
final readonly class ResolveDnsAlertsWhenRecordFixed
{
    public function __construct(
        private AlertRepository $alertRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(DnsCheckCompleted $event): void
    {
        if (!$event->isValid) {
            return;
        }

        $now = $this->clock->now();

        foreach ($this->alertRepository->findUnresolvedDnsProblemsForDomain($event->domainId, $event->type) as $alert) {
            $alert->resolve($now);
        }
    }
}
