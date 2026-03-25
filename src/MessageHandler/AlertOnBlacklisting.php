<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\BlacklistCheckCompleted;
use App\Repository\MonitoredDomainRepository;
use App\Services\AlertEngine;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AlertOnBlacklisting
{
    public function __construct(
        private AlertEngine $alertEngine,
        private MonitoredDomainRepository $monitoredDomainRepository,
    ) {
    }

    public function __invoke(BlacklistCheckCompleted $event): void
    {
        if (!$event->isListed) {
            return;
        }

        $domain = $this->monitoredDomainRepository->get($event->domainId);

        $blacklistNames = implode(', ', array_slice($event->listedOn, 0, 3));
        $suffix = count($event->listedOn) > 3 ? sprintf(' and %d more', count($event->listedOn) - 3) : '';

        $this->alertEngine->createAlert(
            team: $domain->team,
            monitoredDomain: $domain,
            type: AlertType::IpBlacklisted,
            severity: AlertSeverity::Critical,
            title: "IP {$event->ipAddress} blacklisted for {$domain->domain}",
            message: "Sending IP {$event->ipAddress} is listed on: {$blacklistNames}{$suffix}. This may affect email deliverability. Review the blacklist status and take action to delist.",
            data: [
                'ip_address' => $event->ipAddress,
                'listed_on' => $event->listedOn,
            ],
        );
    }
}
