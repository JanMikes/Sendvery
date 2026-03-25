<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DmarcReportProcessed;
use App\Repository\MonitoredDomainRepository;
use App\Services\SenderDiscovery;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateSenderInventoryOnReport
{
    public function __construct(
        private SenderDiscovery $senderDiscovery,
        private MonitoredDomainRepository $monitoredDomainRepository,
    ) {
    }

    public function __invoke(DmarcReportProcessed $event): void
    {
        $domain = $this->monitoredDomainRepository->get($event->domainId);

        $this->senderDiscovery->updateFromReport($domain, $event->reportId);
    }
}
