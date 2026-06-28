<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PauseAutoRamp;
use App\Repository\MonitoredDomainRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cron/safety-only: loads via get() because the message carries no teamId and is
 * dispatched only by the trusted auto-ramp cron / safety rails.
 */
#[AsMessageHandler]
final readonly class PauseAutoRampHandler
{
    public function __construct(
        private MonitoredDomainRepository $monitoredDomainRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PauseAutoRamp $message): void
    {
        $domain = $this->monitoredDomainRepository->get($message->domainId);
        $domain->pauseAutoRamp($message->reason, $this->clock->now());
    }
}
