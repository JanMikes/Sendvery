<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ScheduleAutoRampAdvance;
use App\Repository\MonitoredDomainRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cron-only: loads via get() because the message carries no teamId and is
 * dispatched only by the trusted auto-ramp cron.
 */
#[AsMessageHandler]
final readonly class ScheduleAutoRampAdvanceHandler
{
    public function __construct(
        private MonitoredDomainRepository $monitoredDomainRepository,
    ) {
    }

    public function __invoke(ScheduleAutoRampAdvance $message): void
    {
        $domain = $this->monitoredDomainRepository->get($message->domainId);
        $domain->scheduleAutoRampAdvance($message->to, $message->at);
    }
}
