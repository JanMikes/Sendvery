<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DnsCheckCompleted;
use App\Message\GenerateRemediationInsight;
use App\Value\DnsCheckType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * When a DNS check fails, queue AI remediation guidance (async) so it's ready
 * for the next domain-health view without a blocking API call on the request.
 * MX is out of scope — Sendvery doesn't run inbound mail. The async worker gates
 * on the team's AI plan, so this stays a tiny dispatcher.
 */
#[AsMessageHandler]
final readonly class GenerateRemediationInsightWhenDnsCheckFails
{
    public function __construct(
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(DnsCheckCompleted $event): void
    {
        if ($event->isValid || DnsCheckType::Mx === $event->type) {
            return;
        }

        $this->commandBus->dispatch(new GenerateRemediationInsight(
            domainId: $event->domainId,
            teamId: $event->teamId,
            recordType: $event->type,
            dnsCheckResultId: $event->dnsCheckResultId,
        ));
    }
}
