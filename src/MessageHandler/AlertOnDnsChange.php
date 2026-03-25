<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Events\DnsCheckCompleted;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamRepository;
use App\Services\AlertEngine;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AlertOnDnsChange
{
    public function __construct(
        private AlertEngine $alertEngine,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private TeamRepository $teamRepository,
    ) {
    }

    public function __invoke(DnsCheckCompleted $event): void
    {
        $domain = $this->monitoredDomainRepository->get($event->domainId);
        $team = $this->teamRepository->get($event->teamId);
        $typeName = strtoupper($event->type->value);

        if (null !== $event->previousRawRecord && null === $event->rawRecord) {
            $this->alertEngine->createAlert(
                team: $team,
                monitoredDomain: $domain,
                type: AlertType::DnsRecordMissing,
                severity: AlertSeverity::Critical,
                title: "{$typeName} record removed for {$domain->domain}",
                message: "The {$typeName} record for {$domain->domain} was previously present but is now missing. This can impact email delivery and authentication.",
                data: [
                    'dns_check_type' => $event->type->value,
                    'previous_record' => $event->previousRawRecord,
                ],
            );

            return;
        }

        if ($event->hasChanged && null !== $event->rawRecord) {
            if (!$event->isValid) {
                $this->alertEngine->createAlert(
                    team: $team,
                    monitoredDomain: $domain,
                    type: AlertType::DnsRecordInvalid,
                    severity: AlertSeverity::Critical,
                    title: "{$typeName} record is now invalid for {$domain->domain}",
                    message: "The {$typeName} record for {$domain->domain} has changed and is now invalid. Check your DNS configuration immediately.",
                    data: [
                        'dns_check_type' => $event->type->value,
                        'current_record' => $event->rawRecord,
                        'previous_record' => $event->previousRawRecord,
                    ],
                );

                return;
            }

            $this->alertEngine->createAlert(
                team: $team,
                monitoredDomain: $domain,
                type: AlertType::DnsRecordChanged,
                severity: AlertSeverity::Warning,
                title: "{$typeName} record changed for {$domain->domain}",
                message: "The {$typeName} record for {$domain->domain} has been modified. Review the change to ensure it was intentional.",
                data: [
                    'dns_check_type' => $event->type->value,
                    'current_record' => $event->rawRecord,
                    'previous_record' => $event->previousRawRecord,
                ],
            );
        }
    }
}
